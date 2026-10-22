<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Adapters;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use OpenApi\Spec as OA;
use Radebatz\OpenApi\Introspector\AdapterInterface;
use Radebatz\OpenApi\Introspector\Routing\Constraint;
use Radebatz\OpenApi\Introspector\Routing\Handler;
use Radebatz\OpenApi\Introspector\Routing\Prefix;

/**
 * Reads the routes a Laravel router holds. Everything the introspector knows about a Laravel
 * route comes from here; what a route contributes, and what wins where attributes describe it
 * too, is in the README.
 *
 * It takes the router, its route collection, or any iterable of routes, so a caller that wants
 * only some of them filters first and hands over what is left. A `prefix` comes off every route
 * path that starts with it, for a document whose paths are relative to a server URL carrying it.
 */
final readonly class LaravelAdapter implements AdapterInterface
{
    /**
     * @param Router|RouteCollectionInterface|iterable<Route> $routes
     */
    public function __construct(
        private Router|RouteCollectionInterface|iterable $routes,
        private bool $nameAsOperationId = false,
        private Handler $handler = new Handler(),
        private Constraint $constraint = new Constraint(),
        private string $prefix = '',
    ) {
    }

    public function operations(): iterable
    {
        $routes = match (true) {
            $this->routes instanceof Router => $this->routes->getRoutes()->getRoutes(),
            $this->routes instanceof RouteCollectionInterface => $this->routes->getRoutes(),
            default => $this->routes,
        };

        foreach ($routes as $route) {
            if ($route->isFallback) {
                continue;
            }

            $reflector = $this->handler->reflectCallable($route->getAction('uses'));
            $name = $this->nameAsOperationId ? $route->getName() : null;

            foreach ($this->paths($route) as [$path, $parameters]) {
                foreach ($this->methods($route) as $method) {
                    $operation = new OA\Operation(
                        path: Prefix::strip($this->prefix, $path),
                        method: $method,
                        operationId: $name,
                        parameters: $this->parameters($parameters, $route->wheres, $reflector),
                        responses: $this->responses($parameters, $reflector),
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
    private function methods(Route $route): array
    {
        $methods = array_map(strtolower(...), $route->methods());

        if (in_array('get', $methods, true)) {
            $methods = array_diff($methods, ['head']);
        }

        return array_values($methods);
    }

    /**
     * A route with optional parameters serves one path per combination present, so it is
     * yielded as that many paths.
     *
     * @return iterable<array{string, list<string>}> path and the parameters on it
     */
    private function paths(Route $route): iterable
    {
        $uri = '/' . ltrim($route->uri(), '/');
        preg_match_all('/\{(\w+)\??\}/', $uri, $matches);
        $names = $matches[1];
        $optional = array_keys($route->getOptionalParameterNames());
        $required = array_values(array_diff($names, $optional));

        for ($present = count($optional); $present >= 0; $present--) {
            $path = $uri;
            foreach (array_slice($optional, $present) as $absent) {
                $path = (string) preg_replace('#/\{' . preg_quote($absent, '#') . '\?\}#', '', $path);
            }
            $path = str_replace('?}', '}', $path);

            yield [$path === '' ? '/' : $path, array_merge($required, array_slice($optional, 0, $present))];
        }
    }

    /**
     * @param list<string>          $names
     * @param array<string, string> $wheres
     *
     * @return list<OA\Parameter>|null
     */
    private function parameters(array $names, array $wheres, ?\ReflectionMethod $handler): ?array
    {
        $parameters = [];
        foreach ($names as $name) {
            $reflector = $this->handler->parameter($handler, $name);
            $parameter = new OA\Parameter\Path(name: $name, schema: $this->constraint->schema($wheres[$name] ?? null, $reflector instanceof \ReflectionParameter));
            $parameter->setReflector($reflector);
            $parameters[] = $parameter;
        }

        return $parameters === [] ? null : $parameters;
    }

    /**
     * @param list<string> $names
     *
     * @return list<OA\Response>|null
     */
    private function responses(array $names, ?\ReflectionMethod $handler): ?array
    {
        foreach ($names as $name) {
            if ($this->isBound($handler, $name)) {
                return [new OA\Response(response: 404, description: 'Not Found')];
            }
        }

        return null;
    }

    /**
     * Whether Laravel binds a placeholder to a model and answers 404 when no row matches.
     *
     * Implicit binding, as Laravel does it: the handler's parameter of that name, or of its
     * camel case form, is typed with a routable class, such as an Eloquent model. A default
     * takes the place of a missing row, so a parameter with one is never a 404.
     */
    private function isBound(?\ReflectionMethod $handler, string $name): bool
    {
        foreach ($handler?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() !== $name && Str::snake($parameter->getName()) !== $name) {
                continue;
            }

            $type = $parameter->getType();

            return $type instanceof \ReflectionNamedType
                && !$type->isBuiltin()
                && is_a($type->getName(), UrlRoutable::class, true)
                && !$parameter->isDefaultValueAvailable();
        }

        return false;
    }
}
