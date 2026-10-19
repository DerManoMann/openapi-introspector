<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

use OpenApi\Assembler\AbstractAttributeTranslator;
use OpenApi\Spec as OA;

/**
 * Makes sure a routed handler method has an operation while it is assembled, so the attributes
 * on it nest into that operation the way they nest into one written out by hand.
 *
 * A method carrying only `#[OA\Response]` gets the route's operation for the response to join.
 * An operation the attributes already declare is given the route's path and method where it
 * leaves them out. Routes serving one path with several methods give one operation with
 * `methods:`, which swagger-php's `OperationMethodsTranslator` splits after the attributes
 * have nested, so each method gets them all. Nothing else is filled here: the route's
 * parameters wait until nesting is done, and {@see Introspector::introspect()} folds them in
 * then.
 *
 * An operation it creates carries its class as a `meta` key. Attributes it cannot see, such as
 * classic ones that hybrid mode assembles later, describe the route on an operation without it.
 *
 * Registered after the default translators, so they have already created the attributes it
 * looks at, and before `OperationMethodsTranslator`, so what it adds is split.
 *
 * @internal
 */
final class RouteTranslator extends AbstractAttributeTranslator
{
    public function __construct(private readonly Introspector $introspector)
    {
    }

    public function translate(array $attributes, array $created, \ReflectionClass|\ReflectionMethod|\ReflectionProperty|\ReflectionParameter|\ReflectionClassConstant $reflector): array
    {
        // this translator reads no attributes of its own, so `$created` is empty and `$attributes`
        // is what the earlier translators made from this one reflector
        $translated = [...$attributes, ...$created];

        // operations only sit on methods; the method's parameters are a reflector of their own,
        // read after it, and nest into whatever operation the method ends up with
        if (!$reflector instanceof \ReflectionMethod) {
            return $translated;
        }

        // a method no route is handled by is left to the attributes
        $routes = $this->introspector->routes();
        $candidates = $routes->forHandler($reflector);
        if ($candidates === []) {
            return $translated;
        }

        $operations = array_filter($translated, static fn (object $attribute): bool => $attribute instanceof OA\Operation);

        if ($operations === []) {
            // only path and method: the route's parameters wait until the PHP parameters'
            // attributes have nested, so the two can be folded by name rather than doubled
            if (count($candidates) === 1) {
                $route = $routes->operation($candidates[0]);

                return [(new OA\Operation(path: $route->path, method: $route->method))->setMeta(self::class, true), ...$translated];
            }

            // one path served for several methods: one operation, split per method later
            $path = $this->sharedPath($candidates);
            if ($path !== null) {
                return [(new OA\Operation(path: $path, methods: $this->methodsOf($candidates)))->setMeta(self::class, true), ...$translated];
            }

            // with several paths a sibling would not know which operation to join; the routes
            // are contributed whole instead
            return $translated;
        }

        // the attributes declare operations already: fill in what they leave out, provided
        // their method, where set, leaves a single route to take it from
        foreach ($operations as $operation) {
            if ($operation->path !== null && $operation->method !== null) {
                continue;
            }

            $matching = array_values(array_filter(
                $candidates,
                static fn (int $index): bool => $operation->method === null || $routes->operation($index)->method === $operation->method,
            ));

            if (count($matching) === 1) {
                $route = $routes->operation($matching[0]);
                $operation->path ??= $route->path;
                $operation->method ??= $route->method;

                continue;
            }

            // no method of its own, and one path served for several: it describes all of them
            $path = $this->sharedPath($matching);
            if ($operation->method === null && $operation->methods === null && $path !== null) {
                $operation->path ??= $path;
                $operation->methods = $this->methodsOf($matching);
            }
        }

        return $translated;
    }

    /**
     * The path the routes all serve, if there are several and they agree on one.
     *
     * @param list<int> $indexes
     */
    private function sharedPath(array $indexes): ?string
    {
        $routes = $this->introspector->routes();
        $paths = array_unique(array_map(static fn (int $index): ?string => $routes->operation($index)->path, $indexes));

        return count($indexes) > 1 && count($paths) === 1 ? $paths[array_key_first($paths)] : null;
    }

    /**
     * @param list<int> $indexes
     *
     * @return list<string>
     */
    private function methodsOf(array $indexes): array
    {
        $routes = $this->introspector->routes();

        return array_values(array_filter(array_map(static fn (int $index): ?string => $routes->operation($index)->method, $indexes)));
    }
}
