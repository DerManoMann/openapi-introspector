<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Adapters;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use OpenApi\Spec as OA;
use Psr\Log\LoggerInterface;
use Radebatz\OpenApi\Introspector\AdapterInterface;
use Radebatz\OpenApi\Introspector\Routing\Constraint;
use Radebatz\OpenApi\Introspector\Routing\Handler;
use Radebatz\OpenApi\Introspector\Routing\Laravel\Authentication;
use Radebatz\OpenApi\Introspector\Routing\Laravel\Authorization;
use Radebatz\OpenApi\Introspector\Routing\Laravel\ValidationRules;
use Radebatz\OpenApi\Introspector\Routing\Prefix;

/**
 * Reads the routes a Laravel router holds. Everything the introspector knows about a Laravel
 * route comes from here; what a route contributes, and what wins where attributes describe it
 * too, is in the README.
 *
 * It takes the router, its route collection, or any iterable of routes, so a caller that wants
 * only some of them filters first and hands over what is left. A `prefix` comes off every route
 * path that starts with it, for a document whose paths are relative to a server URL carrying it.
 * The logger hears about a form request whose rules cannot be read.
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
        private ValidationRules $rules = new ValidationRules(),
        private ?LoggerInterface $logger = null,
        private Authentication $authentication = new Authentication(),
        private Authorization $authorization = new Authorization(),
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
            $input = $this->input($reflector, $route);
            $middleware = $this->middleware($route);
            $guards = $this->authentication->guards($middleware);
            $authorizes = $this->authorization->authorizes($middleware);

            foreach ($this->paths($route) as [$path, $parameters]) {
                foreach ($this->methods($route) as $method) {
                    $inBody = in_array($method, ['post', 'put', 'patch'], true);
                    $operation = new OA\Operation(
                        path: Prefix::strip($this->prefix, $path),
                        method: $method,
                        operationId: $name,
                        parameters: $this->parameters($parameters, $route->wheres, $reflector, $inBody ? [] : $input ?? []),
                        requestBody: $inBody ? $this->requestBody($input ?? []) : null,
                        responses: $this->responses($parameters, $reflector, $input !== null, $guards !== null, $authorizes),
                        security: $guards !== null ? $this->authentication->requirements($guards) : null,
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
     * @param list<string>                          $names
     * @param array<string, string>                 $wheres
     * @param array<string, array{OA\Schema, bool}> $query
     *
     * @return list<OA\Parameter>|null
     */
    private function parameters(array $names, array $wheres, ?\ReflectionMethod $handler, array $query): ?array
    {
        $parameters = [];
        foreach ($names as $name) {
            $reflector = $this->handler->parameter($handler, $name);
            $parameter = new OA\Parameter\Path(name: $name, schema: $this->constraint->schema($wheres[$name] ?? null, $reflector instanceof \ReflectionParameter));
            $parameter->setReflector($reflector);
            $parameters[] = $parameter;
        }

        foreach ($query as $name => [$schema, $required]) {
            $parameters[] = new OA\Parameter\Query(name: $name, required: $required ?: null, schema: $schema->copy());
        }

        return $parameters === [] ? null : $parameters;
    }

    /**
     * @param array<string, array{OA\Schema, bool}> $fields
     */
    private function requestBody(array $fields): ?OA\RequestBody
    {
        if ($fields === []) {
            return null;
        }

        $properties = [];
        $required = [];
        foreach ($fields as $name => [$schema, $isRequired]) {
            $properties[$name] = $schema->copy();
            if ($isRequired) {
                $required[] = $name;
            }
        }

        return new OA\RequestBody(
            required: $required !== [] ?: null,
            content: new OA\Schema(type: 'object', properties: $properties, required: $required ?: null),
        );
    }

    /**
     * @param list<string> $names
     *
     * @return list<OA\Response>|null
     */
    private function responses(array $names, ?\ReflectionMethod $handler, bool $validates, bool $authenticates, bool $authorizes): ?array
    {
        $responses = [];
        if ($authenticates) {
            $responses[] = new OA\Response(response: 401, description: 'Unauthorized');
        }
        if ($authorizes) {
            $responses[] = new OA\Response(response: 403, description: 'Forbidden');
        }
        foreach ($names as $name) {
            if ($this->isBound($handler, $name)) {
                $responses[] = new OA\Response(response: 404, description: 'Not Found');
                break;
            }
        }
        if ($validates) {
            $responses[] = new OA\Response(response: 422, description: 'Unprocessable Content');
        }

        return $responses === [] ? null : $responses;
    }

    /**
     * The route's middleware and its controller's, less what the route excludes.
     *
     * Reading a controller's middleware can build the controller, which needs what the application
     * provides; where that fails, the route's own middleware is what there is.
     *
     * @return list<mixed>
     */
    private function middleware(Route $route): array
    {
        try {
            $middleware = $route->gatherMiddleware();
        } catch (\Throwable) {
            $middleware = $route->middleware();
        }

        return array_values(array_diff(array_filter($middleware, is_string(...)), $route->excludedMiddleware()));
    }

    /**
     * The fields a handler's form request validates, or `null` where it takes none.
     *
     * Laravel validates a `FormRequest` in the signature before the handler runs, and answers 422
     * when it fails. Its `rules()` is called here on a bare instance, outside any request, so one
     * that reads the user, the route or the input sees none of them. Where that fails, the fields
     * are left out and the logger says why; the request still validates, so the 422 stays.
     *
     * @return array<string, array{OA\Schema, bool}>|null
     */
    private function input(?\ReflectionMethod $handler, Route $route): ?array
    {
        foreach ($handler?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !is_a($class = $type->getName(), FormRequest::class, true)) {
                continue;
            }

            if (!method_exists($class, 'rules')) {
                return [];
            }

            try {
                if ((new \ReflectionMethod($class, 'rules'))->getNumberOfRequiredParameters() > 0) {
                    throw new \RuntimeException('rules() takes arguments');
                }
                // a warning, such as reading a property of the user there is none of, fails it too
                set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
                    throw new \ErrorException($message, 0, $severity, $file, $line);
                });
                try {
                    $rules = (new $class())->rules();
                } finally {
                    restore_error_handler();
                }
            } catch (\Throwable $throwable) {
                $this->logger?->warning(sprintf('Cannot read the rules of %s for %s: %s', $class, $route->uri(), $throwable->getMessage()));

                return [];
            }

            return is_array($rules) ? $this->rules->fields($rules) : [];
        }

        return null;
    }

    /**
     * Whether Laravel binds a placeholder to a model and answers 404 when no row matches.
     *
     * Implicit binding, as Laravel does it: the handler's parameter of that name, or of its
     * camel case form, is typed with a routable class, such as an Eloquent model. A default does
     * not change that: Laravel only skips binding where an optional placeholder is absent, and
     * then the placeholder is not on the path asked about.
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
                && is_a($type->getName(), UrlRoutable::class, true);
        }

        return false;
    }
}
