<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Routing;

use OpenApi\Spec as OA;
use OpenApi\Utils\SourceLocation;
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

    /** @var array<string, list<int>> */
    private array $shapes = [];

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
                $this->shapes[Entry::keyOf($operation->method, self::shapeOf($operation->path))][] = $index;
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
        return (string) SourceLocation::fromReflector($method)->qualifiedMethod();
    }

    /**
     * A path with its placeholder names left out, so paths differing only in those compare equal.
     */
    public static function shapeOf(string $path): string
    {
        return (string) preg_replace('/\{[^}\/]+\}/', '{}', $path);
    }

    /**
     * The names of a path's placeholders, in the order they appear.
     *
     * @return list<string>
     */
    public static function placeholdersOf(string $path): array
    {
        preg_match_all('/\{([^}\/]+)\}/', $path, $matches);

        return $matches[1];
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
     *
     * Where none has that exact path, the one route of the same shape is taken, whatever its
     * placeholders are called. Several of one shape are told apart by their constraints, such as
     * `{order_id}` and `{hash}`, which a path alone cannot do, so then there is none.
     */
    public function forKey(string $method, string $path): ?int
    {
        if (isset($this->keys[$key = Entry::keyOf($method, $path)])) {
            return $this->keys[$key];
        }

        $shaped = $this->shapes[Entry::keyOf($method, self::shapeOf($path))] ?? [];

        return count($shaped) === 1 ? $shaped[0] : null;
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
