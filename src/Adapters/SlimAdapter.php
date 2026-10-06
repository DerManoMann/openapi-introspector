<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Adapters;

use OpenApi\Spec as OA;
use Radebatz\OpenApi\Introspector\AdapterInterface;
use Radebatz\OpenApi\Introspector\Routing\Constraint;
use Radebatz\OpenApi\Introspector\Routing\Handler;
use Slim\Interfaces\RouteCollectorInterface;
use Slim\Interfaces\RouteInterface;

/**
 * Reads the routes a Slim route collector holds.
 *
 * Slim keeps the constraint inside the pattern (`/users/{id:[0-9]+}`) and marks optional
 * segments with brackets (`/users[/{id}]`), so both are parsed out here. A class-based
 * callable carries its method's reflector; a closure is yielded bare.
 *
 * It takes the route collector or any iterable of routes, so a caller that wants only some of
 * them filters first and hands over what is left.
 */
final readonly class SlimAdapter implements AdapterInterface
{
    /**
     * @param RouteCollectorInterface|iterable<RouteInterface> $routes
     */
    public function __construct(
        private RouteCollectorInterface|iterable $routes,
        private bool $nameAsOperationId = false,
        private Handler $handler = new Handler(),
        private Constraint $constraint = new Constraint(),
    ) {
    }

    public function operations(): iterable
    {
        $routes = $this->routes instanceof RouteCollectorInterface ? $this->routes->getRoutes() : $this->routes;

        foreach ($routes as $route) {
            $reflector = $this->handler->reflectCallable($route->getCallable());
            $name = $this->nameAsOperationId ? $route->getName() : null;

            foreach (self::expand($route->getPattern()) as $variant) {
                [$path, $parameters] = $this->parse($variant);

                foreach ($this->methods($route) as $method) {
                    $operation = new OA\Operation(
                        path: $path,
                        method: $method,
                        operationId: $name,
                        parameters: $this->parameters($parameters),
                    );
                    $operation->setReflector($reflector);

                    yield $operation;
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function methods(RouteInterface $route): array
    {
        $methods = array_map(strtolower(...), $route->getMethods());

        if (in_array('get', $methods, true)) {
            $methods = array_diff($methods, ['head']);
        }

        return array_values($methods);
    }

    /**
     * Expands FastRoute's optional segments into every pattern the route matches.
     *
     * Only brackets outside a placeholder open a segment; `{id:[0-9]+}` keeps its class.
     *
     * @return list<string>
     */
    private static function expand(string $pattern): array
    {
        $open = null;
        $close = null;
        $depth = 0;
        foreach (str_split($pattern) as $offset => $char) {
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            } elseif ($depth === 0 && $char === '[' && $open === null) {
                $open = $offset;
            } elseif ($depth === 0 && $char === ']') {
                $close = $offset;
            }
        }

        if ($open === null || $close === null || $close < $open) {
            return [$pattern];
        }

        $before = substr($pattern, 0, $open);
        $inner = substr($pattern, $open + 1, $close - $open - 1);
        $after = substr($pattern, $close + 1);

        return [...self::expand($before . $after), ...self::expand($before . $inner . $after)];
    }

    /**
     * Splits `{name:regex}` placeholders out of a pattern.
     *
     * @return array{string, array<string, string|null>} the path with plain placeholders, and each parameter's constraint
     */
    private function parse(string $pattern): array
    {
        $parameters = [];
        $path = (string) preg_replace_callback(
            '/\{(\w+)(?::([^{}]*(?:\{[^{}]*\}[^{}]*)*))?\}/',
            static function (array $match) use (&$parameters): string {
                $parameters[$match[1]] = isset($match[2]) && $match[2] !== '' ? $match[2] : null;

                return '{' . $match[1] . '}';
            },
            $pattern,
        );

        return [$path === '' ? '/' : $path, $parameters];
    }

    /**
     * @param array<string, string|null> $parameters
     *
     * @return list<OA\Parameter>|null
     */
    private function parameters(array $parameters): ?array
    {
        $result = [];
        foreach ($parameters as $name => $pattern) {
            $result[] = new OA\Parameter\Path(name: $name, schema: $this->constraint->schema($pattern));
        }

        return $result === [] ? null : $result;
    }
}
