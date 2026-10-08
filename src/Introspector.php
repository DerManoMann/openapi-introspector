<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

use OpenApi\Builder;
use OpenApi\Contracts\AttributeInterface;
use OpenApi\Spec as OA;
use OpenApi\Spec\Schema;
use OpenApi\Specification;
use OpenApi\Specification\ComponentIndex;
use OpenApi\Specification\PathItemHierarchy;
use OpenApi\Utils\AttributeFactory;
use OpenApi\Utils\Pipeline;
use OpenApi\Utils\TypedList;
use Radebatz\OpenApi\Introspector\Inventory\Entry;
use Radebatz\OpenApi\Introspector\Routing\Routes;

/**
 * Runs the adapters inside a swagger-php build, and decides what of theirs goes in.
 *
 * Attributes add to what a route gives: a {@see RouteTranslator} gives the handler method an
 * operation while it is assembled, and once nesting is done the route's parameters are folded
 * in field by field, the attribute winning where both set one. An operation declared on another
 * method for the route's method and path is completed the same way, and only the route's own
 * operation stands aside. Between adapters, the first to yield a method and path wins.
 */
final class Introspector
{
    /** @var list<AdapterInterface> */
    private array $adapters = [];

    private ?Routes $routes = null;

    private ?Inventory $inventory = null;

    public function withAdapter(AdapterInterface ...$adapters): static
    {
        foreach ($adapters as $adapter) {
            $this->adapters[] = $adapter;
        }
        $this->routes = null;

        return $this;
    }

    /**
     * Registers the adapters on the builder: a translator for assembly, and the introspection as
     * the first augmenter.
     */
    public function register(Builder $builder): Builder
    {
        $builder->withAttributeFactory(fn (AttributeFactory $factory): AttributeFactory => $factory->withTranslators(
            fn (TypedList $translators): TypedList => $translators->add(new RouteTranslator($this)),
        ));

        $builder->withAugmenters(fn (Pipeline $augmenters): Pipeline => $augmenters->insert(
            new IntrospectionAugmenter($this),
            static fn (): int => 0,
        ));

        return $builder;
    }

    /**
     * The adapters' routes, read on first use and kept until another adapter is added.
     *
     * @internal
     */
    public function routes(): Routes
    {
        return $this->routes ??= new Routes($this->adapters);
    }

    /**
     * Completes the operations routes serve, adds the routes nothing describes, and records every
     * operation it serves or describes.
     *
     * Runs once assembly and nesting are done, so every operation an attribute declared is in the
     * specification with all of its children, including any the {@see RouteTranslator} added for
     * a handler. It works in four steps:
     *
     * 1. Pair each assembled operation with the route it is for, if any.
     * 2. Complete each paired operation from its route. Where a route has more than one operation,
     *    one only the translator made stands aside, so attributes keep the final say.
     * 3. Contribute every route no operation was paired with, as the adapter yielded it.
     * 4. Record what attributes describe and no route serves as unrouted.
     */
    public function introspect(Specification $specification): Inventory
    {
        $inventory = new Inventory();
        $routes = $this->routes();
        $hierarchy = $specification->buildPathItemHierarchy();
        $components = $specification->buildComponentIndex();

        // 1. pair operations with routes. An operation's key uses the path it compiles to, with
        // its controller's prefix in front, so it can be compared with a route's full path.
        // "Described" means attributes contributed to it, rather than only the translator.
        /** @var list<array{OA\Operation, int|null, string, bool}> $assembled operation, route, key, described */
        $assembled = [];
        /** @var array<string, array{string, string}> $described method and path, by key */
        $described = [];
        foreach ($specification->operations as $operation) {
            $index = $this->routeOf($routes, $operation, $hierarchy);
            $route = $index !== null ? $routes->operation($index) : null;
            $path = $route->path ?? ($operation->path !== null ? $this->join($this->prefixOf($operation, $hierarchy), $operation->path) : null);

            // incomplete operations are left to the pipeline, which reports them
            if ($path === null || $operation->method === null) {
                continue;
            }

            $key = Entry::keyOf($operation->method, $path);
            // an operation no route is paired with can only have come from attributes
            $isDescribed = $index === null || $this->isDescribed($operation);
            $assembled[] = [$operation, $index, $key, $isDescribed];

            if ($isDescribed) {
                $described[$key] = [$operation->method, $path];
            }
        }

        // 2. complete paired operations from their routes
        /** @var array<int, true> $claimed route indexes that have an operation in the document */
        $claimed = [];
        $standingAside = [];
        foreach ($assembled as [$operation, $index, $key, $isDescribed]) {
            if ($index === null) {
                continue;
            }

            // the translator adds an operation for a handler before it can know whether
            // attributes on another method describe the same route; if they do, or another
            // operation already claimed the route, the translator's one is redundant
            if (!$isDescribed && (isset($claimed[$index]) || isset($described[$key]))) {
                $standingAside[] = $operation;

                continue;
            }

            $claimed[$index] = true;
            $route = $routes->operation($index);
            $this->complete($components, $operation, $route, $hierarchy);
            $inventory->record(new Entry((string) $route->method, (string) $route->path, $isDescribed ? Status::Matched : Status::Introspected, $routes->adapter($index)));
        }

        if ($standingAside !== []) {
            $specification->operations = array_values(array_filter(
                $specification->operations,
                static fn (OA\Operation $operation): bool => !in_array($operation, $standingAside, true),
            ));
        }

        // 3. contribute the routes nothing claimed: closures, handlers outside the scanned
        // sources, and handlers serving several routes with no attribute naming one of them.
        // Step 1 pairs any described operation with the route for its key, so nothing here is
        // described elsewhere. The copy keeps the cached route unchanged for the next build.
        foreach ($routes->indexes() as $index) {
            if (isset($claimed[$index])) {
                continue;
            }

            $route = $routes->operation($index);
            $contributed = self::copy($route);
            $this->relativise($contributed, $hierarchy);
            $specification->add($contributed);
            $inventory->record(new Entry((string) $route->method, (string) $route->path, Status::Introspected, $routes->adapter($index)));
        }

        // 4. what attributes describe and no route serves stays in the document, and is reported
        foreach ($described as [$method, $path]) {
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
     * The route an assembled operation is for: one its handler method serves, picked by method,
     * then by path where the handler serves several, or else the route for its method and full
     * path, wherever it is declared.
     */
    private function routeOf(Routes $routes, OA\Operation $operation, PathItemHierarchy $hierarchy): ?int
    {
        if ($operation->method === null) {
            return null;
        }

        $reflector = $operation->getReflector();
        // the path the operation compiles to; the translator sets a route's full path, while an
        // attribute under a prefixed controller is written relative to the prefix
        $full = $operation->path !== null ? $this->join($this->prefixOf($operation, $hierarchy), $operation->path) : null;

        // first, the routes its own method handles, for the same HTTP method
        $candidates = array_values(array_filter(
            $reflector instanceof \ReflectionMethod ? $routes->forHandler($reflector) : [],
            static fn (int $index): bool => $routes->operation($index)->method === $operation->method,
        ));

        // a handler serving several routes, such as one with an optional parameter: the
        // attribute's path picks one, compared both as written and with the prefix in front
        if (count($candidates) > 1 && $operation->path !== null) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (int $index): bool => in_array($routes->operation($index)->path, [$operation->path, $full], true),
            ));
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // otherwise an operation declared anywhere is for the route serving its method and path
        return $full !== null ? $routes->forKey($operation->method, $full) : null;
    }

    /**
     * Folds what the route knows into an assembled operation, where the operation leaves it out.
     *
     * The operation is the base and wins on every field it sets. From the route it takes the
     * `operationId` and the path parameters: a parameter it lacks is added, and one it has gets the
     * route's schema where it has none, or the route's `pattern` where its schema has none.
     */
    private function complete(ComponentIndex $components, OA\Operation $operation, OA\Operation $route, PathItemHierarchy $hierarchy): void
    {
        // a path equal to the route's is the full path, which the translator set; the pipeline
        // still puts the controller's prefix in front, so it has to come off here
        if ($operation->path === $route->path) {
            $this->relativise($operation, $hierarchy);
        }

        $operation->operationId ??= $route->operationId;

        foreach ($route->parameters ?? [] as $parameter) {
            $existing = $this->findParameter($components, $operation, (string) $parameter->name, (string) $parameter->in);

            // `null` is a reference to a component, which belongs to every operation using it
            // and is not changed for one of them
            if ($existing === false) {
                $operation->parameters ??= [];
                $operation->parameters[] = self::copy($parameter);
            } elseif ($existing instanceof OA\Parameter && $parameter->schema instanceof Schema) {
                if (!$existing->schema instanceof Schema) {
                    $existing->schema = self::copy($parameter->schema);
                } else {
                    $existing->schema->pattern ??= $parameter->schema->pattern;
                }
            }
        }
    }

    /**
     * The parameter an operation already has for a name and location: the inline one to fold
     * into, `null` for a reference to a component, which is left alone, or `false` for none.
     */
    private function findParameter(ComponentIndex $components, OA\Operation $operation, string $name, string $in): OA\Parameter|false|null
    {
        foreach ($operation->parameters ?? [] as $parameter) {
            // the resolver only resolves class references, so a reference to a component still
            // carries no name or location: read them off the component it points to
            if (is_string($parameter->ref)) {
                $component = $components->findParameter($parameter->ref);
                if ($component instanceof OA\Parameter && $component->name === $name && $component->in === $in) {
                    return null;
                }

                continue;
            }

            // names are filled in from the PHP parameter by a later augmenter, so read it here
            $reflector = $parameter->getReflector();
            $parameterName = $parameter->name ?? ($reflector instanceof \ReflectionParameter ? $reflector->getName() : null);

            if ($parameterName === $name && $parameter->in === $in) {
                return $parameter;
            }
        }

        return false;
    }

    /**
     * Whether attributes on the handler, rather than only the route, describe the operation.
     *
     * An operation the translator added carries no attributes of its own, so this reads them off
     * the method it sits on: a bare `#[OA\Response]` there, or an `OA\Parameter` on one of its
     * parameters, means attributes contributed. Reading it off the method needs no mark on the
     * operation, which the pipeline clones and changes as it runs.
     */
    private function isDescribed(OA\Operation $operation): bool
    {
        $reflector = $operation->getReflector();
        // without a handler method, attributes are all that can have made it
        if (!$reflector instanceof \ReflectionMethod) {
            return true;
        }

        if ($reflector->getAttributes(AttributeInterface::class, \ReflectionAttribute::IS_INSTANCEOF) !== []) {
            return true;
        }

        foreach ($reflector->getParameters() as $parameter) {
            if ($parameter->getAttributes(AttributeInterface::class, \ReflectionAttribute::IS_INSTANCEOF) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Takes the prefix of the handler's `#[PathItem]` chain off a route's full path, since the
     * pipeline adds it again: otherwise `/api/users` compiles as `/api/api/users`.
     */
    private function relativise(OA\Operation $operation, PathItemHierarchy $hierarchy): void
    {
        $prefix = $this->prefixOf($operation, $hierarchy);
        $path = (string) $operation->path;

        // a route outside its controller's prefix cannot be expressed under it; the path is left
        // as it is, and the pipeline still puts the prefix in front
        if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
            $operation->path = '/' . ltrim(substr($path, strlen($prefix)), '/');
        }
    }

    /**
     * The prefix the pipeline will put in front of an operation's path, composed the way
     * swagger-php's `Augmenter\PathItems` composes it.
     */
    private function prefixOf(OA\Operation $operation, PathItemHierarchy $hierarchy): string
    {
        $parts = [];
        foreach ($hierarchy->forOperation($operation) as $pathItem) {
            if ($pathItem->prefix !== null && ($part = trim($pathItem->prefix, '/')) !== '') {
                $parts[] = $part;
            }
        }

        return $parts !== [] ? '/' . implode('/', $parts) : '';
    }

    /**
     * A copy of what a route holds, so a build changes the copy and not the route kept for the next.
     *
     * @template T of OA\Operation|OA\Parameter|OA\Schema
     *
     * @param T $attribute
     *
     * @return T
     */
    private static function copy(OA\Operation|OA\Parameter|Schema $attribute): OA\Operation|OA\Parameter|Schema
    {
        // `clone` is shallow, and the children are what later augmenters change
        $copy = clone $attribute;

        if ($copy instanceof OA\Operation && $copy->parameters !== null) {
            $copy->parameters = array_map(self::copy(...), $copy->parameters);
        } elseif ($copy instanceof OA\Parameter && $copy->schema instanceof Schema) {
            $copy->schema = self::copy($copy->schema);
        }

        return $copy;
    }

    private function join(string $prefix, string $path): string
    {
        if ($prefix === '') {
            return $path;
        }

        $path = ltrim($path, '/');

        return $path !== '' ? $prefix . '/' . $path : $prefix;
    }
}
