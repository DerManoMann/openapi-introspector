<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Routing;

use OpenApi\Spec as OA;
use Radebatz\OpenApi\Introspector\AdapterInterface;
use Radebatz\OpenApi\Introspector\Inventory\Entry;

/**
 * Every operation the adapters yield, read once, and indexed by the handler method serving it.
 *
 * Between adapters, the first to yield a method and path wins; a later one for the same key
 * is dropped here, before anything else sees it.
 *
 * @internal
 */
final class Routes
{
    /** @var list<OA\Operation> */
    private array $operations = [];

    /** @var list<class-string> */
    private array $adapters = [];

    /** @var array<string, list<int>> */
    private array $handlers = [];

    /** @var array<string, int> */
    private array $keys = [];

    /**
     * @param iterable<AdapterInterface> $adapters
     *
     * @throws \InvalidArgumentException when an operation has no path or no method, which is a defect in the adapter
     */
    public function __construct(iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            foreach ($adapter->operations() as $operation) {
                if ($operation->path === null || $operation->method === null) {
                    throw new \InvalidArgumentException(sprintf('%s yielded an operation without a path or a method.', $adapter::class));
                }

                // the first adapter to yield a method and path wins
                $key = Entry::keyOf($operation->method, $operation->path);
                if (isset($this->keys[$key])) {
                    continue;
                }

                $index = count($this->operations);
                $this->keys[$key] = $index;
                $this->operations[] = $operation;
                $this->adapters[] = $adapter::class;

                // a closure, or a handler the adapter could not resolve, has no reflector and is
                // found by method and path only
                $reflector = $operation->getReflector();
                if ($reflector instanceof \ReflectionMethod) {
                    $this->handlers[self::handlerOf($reflector)][] = $index;
                }
            }
        }
    }

    /**
     * Identifies a handler by the class declaring it, so a route to `Child::show` and the
     * method the assembler reads while scanning `Base` are the same handler.
     */
    public static function handlerOf(\ReflectionMethod $method): string
    {
        return $method->getDeclaringClass()->getName() . '::' . $method->getName();
    }

    /**
     * @return list<int>
     */
    public function indexes(): array
    {
        return array_keys($this->operations);
    }

    /**
     * The indexes of the routes a handler method serves.
     *
     * @return list<int>
     */
    public function forHandler(\ReflectionMethod $method): array
    {
        return $this->handlers[self::handlerOf($method)] ?? [];
    }

    /**
     * The index of the route for a method and path, if one serves it.
     */
    public function forKey(string $method, string $path): ?int
    {
        return $this->keys[Entry::keyOf($method, $path)] ?? null;
    }

    /**
     * @return OA\Operation with path and method set
     */
    public function operation(int $index): OA\Operation
    {
        return $this->operations[$index];
    }

    /**
     * @return class-string
     */
    public function adapter(int $index): string
    {
        return $this->adapters[$index];
    }
}
