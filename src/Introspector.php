<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

use OpenApi\Builder;
use OpenApi\Spec as OA;
use OpenApi\Specification;
use Radebatz\OpenApi\Introspector\Inventory\Entry;

/**
 * Runs the adapters inside a swagger-php build, and decides what of theirs goes in.
 *
 * Attributes win whole on any method and path they already describe: the pipeline keeps the
 * last operation for a key and drops the rest without a diagnostic, so until it can fold two
 * halves an adapter's operation stands aside rather than replace the scanned one. Between
 * adapters, the first to yield a method and path wins.
 */
final class Introspector
{
    /** @var list<AdapterInterface> */
    private array $adapters = [];

    private ?Inventory $inventory = null;

    public function withAdapter(AdapterInterface ...$adapters): static
    {
        foreach ($adapters as $adapter) {
            $this->adapters[] = $adapter;
        }

        return $this;
    }

    /**
     * Registers the adapters on the builder, to run after assembly and before resolution.
     */
    public function register(Builder $builder): Builder
    {
        $builder->withSpecification(function (Specification $specification): void {
            $this->introspect($specification);
        });

        return $builder;
    }

    /**
     * Adds what the adapters yield to the specification, in registration order, and records
     * every operation it serves or describes.
     */
    public function introspect(Specification $specification): Inventory
    {
        $inventory = new Inventory();

        $scanned = [];
        foreach ($specification->operations as $operation) {
            if ($operation->path !== null && $operation->method !== null) {
                $scanned[Entry::keyOf($operation->method, $operation->path)] = [$operation->method, $operation->path];
            }
        }

        foreach ($this->adapters as $adapter) {
            foreach ($adapter->operations() as $operation) {
                $this->admit($specification, $inventory, $scanned, $operation, $adapter::class);
            }
        }

        foreach ($scanned as [$method, $path]) {
            if (!$inventory->has($method, $path)) {
                $inventory->record(new Entry($method, $path, Status::Unrouted));
            }
        }

        return $this->inventory = $inventory;
    }

    /**
     * The inventory of the most recent introspection.
     */
    public function inventory(): Inventory
    {
        return $this->inventory ?? throw new \LogicException('Nothing has been introspected yet.');
    }

    /**
     * Decides what becomes of one operation an adapter yielded, and records the outcome.
     *
     * The first adapter to yield a method and path wins; a later one for the same key is
     * ignored without a record. Where attributes already describe the key, the operation stands
     * aside and the entry is `Matched`. Otherwise the operation goes into the specification and
     * the entry is `Introspected`.
     *
     * @param array<string, array{string, string}> $scanned the keys the assembler put in, with their method and path
     * @param class-string                         $adapter the adapter that yielded the operation
     *
     * @throws \InvalidArgumentException when the operation has no path or no method, which is a defect in the adapter
     */
    private function admit(Specification $specification, Inventory $inventory, array $scanned, OA\Operation $operation, string $adapter): void
    {
        if ($operation->path === null || $operation->method === null) {
            throw new \InvalidArgumentException(sprintf('%s yielded an operation without a path or a method.', $adapter));
        }

        if ($inventory->has($operation->method, $operation->path)) {
            return;
        }

        if (isset($scanned[Entry::keyOf($operation->method, $operation->path)])) {
            $inventory->record(new Entry($operation->method, $operation->path, Status::Matched, $adapter));

            return;
        }

        $specification->add($operation);
        $inventory->record(new Entry($operation->method, $operation->path, Status::Introspected, $adapter));
    }
}
