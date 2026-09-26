<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Adapters;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use OpenApi\Spec as OA;
use Radebatz\OpenApi\Introspector\AdapterInterface;
use Radebatz\OpenApi\Introspector\Routing\Constraint;
use Radebatz\OpenApi\Introspector\Routing\Handler;

/**
 * Reads the routes a Laravel router holds.
 *
 * A route with a controller action carries that method's reflector, so the pipeline reads the
 * docblock and the signature; a closure route is yielded bare. `HEAD` is dropped as an
 * artefact of `GET`, and a fallback route is not an endpoint.
 */
final readonly class LaravelAdapter implements AdapterInterface
{
    public function __construct(
        private Router|RouteCollectionInterface $routes,
        private bool $nameAsOperationId = false,
        private Handler $handler = new Handler(),
        private Constraint $constraint = new Constraint(),
    ) {
    }

    public function operations(): iterable
    {
        $collection = $this->routes instanceof Router ? $this->routes->getRoutes() : $this->routes;

        foreach ($collection->getRoutes() as $route) {
            if ($route->isFallback) {
                continue;
            }

            $reflector = $this->handler->reflectCallable($route->getAction('uses'));
            $name = $this->nameAsOperationId ? $route->getName() : null;

            foreach ($this->paths($route) as [$path, $parameters]) {
                foreach ($this->methods($route) as $method) {
                    $operation = new OA\Operation(
                        path: $path,
                        method: $method,
                        operationId: $name,
                        parameters: $this->parameters($parameters, $route->wheres),
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
    private function parameters(array $names, array $wheres): ?array
    {
        $parameters = [];
        foreach ($names as $name) {
            $parameters[] = new OA\Parameter\Path(name: $name, schema: $this->constraint->schema($wheres[$name] ?? null));
        }

        return $parameters === [] ? null : $parameters;
    }
}
