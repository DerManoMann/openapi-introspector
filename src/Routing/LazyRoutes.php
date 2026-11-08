<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Routing;

/**
 * Routes read each time they are iterated, not when they are handed over.
 *
 * An adapter created before the application has registered its routes, such as from a plugin's
 * settings in a service provider's `register()`, still documents them as long as the build runs
 * later.
 *
 * @template T
 *
 * @implements \IteratorAggregate<int, T>
 */
final readonly class LazyRoutes implements \IteratorAggregate
{
    /**
     * @param \Closure(): iterable<T> $routes
     */
    public function __construct(private \Closure $routes)
    {
    }

    public function getIterator(): \Generator
    {
        yield from ($this->routes)();
    }
}
