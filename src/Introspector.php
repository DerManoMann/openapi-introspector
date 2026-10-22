<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

use OpenApi\Assembler\OperationMethodsTranslator;
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
use Psr\Log\LoggerInterface;
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

    public function addAdapter(AdapterInterface ...$adapters): static
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
            // before the splitter, so a `methods:` operation it adds is split per method
            fn (TypedList $translators): TypedList => $translators->insert(new RouteTranslator($this), OperationMethodsTranslator::class),
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
     * 1. Pair each assembled operation with the route it is for, if any: one its handler method
     *    serves, or else the one for its method and full path.
     * 2. Complete each paired operation from its route. Where a route has more than one operation,
     *    one only the translator made stands aside, so attributes keep the final say.
     * 3. Contribute every route no operation was paired with, as the adapter yielded it, unless
     *    an attribute operation for another route already sits at its path. That one would be
     *    replaced, so the route is left out, reported to the logger and recorded as a conflict.
     * 4. Record what attributes describe and no route serves as unrouted.
     */
    public function introspect(Specification $specification, ?LoggerInterface $logger = null): Inventory
    {
        $inventory = new Inventory();
        $routes = $this->routes();
        $hierarchy = $specification->buildPathItemHierarchy();
        $components = $specification->buildComponentIndex();

        // 1. pair operations with routes: first by the handler method an operation sits on, and
        // where that leaves no single route, by its method and the path it compiles to, with its
        // controller's prefix in front so it compares with a route's full path. The same path
        // keys the operation here. "Described" means attributes contributed to it, rather than
        // only the translator.
        /** @var list<array{OA\Operation, int|null, string, bool}> $assembled operation, route, key, described */
        $assembled = [];
        /** @var array<string, array{string, string}> $described method and path, by key */
        $described = [];
        /** @var array<string, string> $documented the path each described operation compiles to, by method and shape */
        $documented = [];
        foreach ($specification->operations as $operation) {
            $index = $this->routeOf($routes, $operation, $hierarchy);
            $route = $index !== null ? $routes->operation($index) : null;
            $path = $route->path ?? $hierarchy->pathFor($operation);

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
                $compiled = $hierarchy->pathFor($operation) ?? $path;
                $documented[Entry::keyOf($operation->method, Routes::shapeOf($compiled))] = $compiled;
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

            // nor may it replace an attribute operation documenting another route at its path;
            // the route stays unclaimed, and step 3 reports the conflict
            if (!$isDescribed && isset($documented[Entry::keyOf($operation->method, Routes::shapeOf((string) $routes->operation($index)->path))])) {
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

            // an attribute operation already sits at the route's path, documenting another
            // route: adding this one would replace it, so it is left out and reported
            $taken = $documented[Entry::keyOf((string) $route->method, Routes::shapeOf((string) $route->path))] ?? null;
            if ($taken !== null) {
                $logger?->warning(sprintf(
                    'Route %s %s is not added: attributes document %s %s for another route',
                    strtoupper((string) $route->method),
                    $route->path,
                    strtoupper((string) $route->method),
                    $taken,
                ));
                $inventory->record(new Entry((string) $route->method, (string) $route->path, Status::Conflict, $routes->adapter($index)));

                continue;
            }

            $contributed = self::copy($route);
            $this->relativise($contributed, $hierarchy);
            // the same rule as for a completed operation: what the path items declare is theirs
            if ($contributed->parameters !== null) {
                $contributed->parameters = array_values(array_filter(
                    $contributed->parameters,
                    fn (OA\Parameter $parameter): bool => !$this->isShared($hierarchy, $contributed, $components->parameterKey($parameter)),
                )) ?: null;
            }
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
        $full = $hierarchy->pathFor($operation);

        // first, the routes its own method handles, for the same HTTP method
        $candidates = array_values(array_filter(
            $reflector instanceof \ReflectionMethod ? $routes->forHandler($reflector) : [],
            static fn (int $index): bool => $routes->operation($index)->method === $operation->method,
        ));

        // where the attribute gives a path, the handler's routes have to agree with it: one
        // handler may serve several routes, such as one with an optional parameter, and one
        // method may carry operations for routes other handlers serve. Compared as written and
        // with the prefix in front, then by shape, since the two may name their placeholders
        // differently, and last as the end of the route's path, for an attribute path written
        // relative to a server URL that carries the route's leading segments
        if ($candidates !== [] && $operation->path !== null) {
            $candidates = $this->agreeing($routes, $candidates, array_values(array_filter([$operation->path, $full])));
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
     * The operation is the base and wins on every field it sets but one. From the route it takes
     * the `operationId` and the path parameters, mapped onto its own placeholders by position, so a
     * route's `{event}` is the attribute's `{id}`: a parameter it lacks is added, and one it has
     * gets the route's schema where it has none. Where it has one, the route's `pattern` replaces its
     * own, since that is what the router matches. A parameter its controller's path items declare
     * is left to them. A response is added for a status code the operation has none for, its own
     * range included, so a `4XX` stays the answer for a 404.
     */
    private function complete(ComponentIndex $components, OA\Operation $operation, OA\Operation $route, PathItemHierarchy $hierarchy): void
    {
        // a path equal to the route's is the full path, which the translator set; the pipeline
        // still puts the controller's prefix in front, so it has to come off here
        if ($operation->path === $route->path) {
            $this->relativise($operation, $hierarchy);
        }

        $operation->operationId ??= $route->operationId;
        $placeholders = $this->placeholderNames($hierarchy->pathFor($operation) ?? $operation->path ?? (string) $route->path, (string) $route->path);

        foreach ($route->parameters ?? [] as $parameter) {
            // a path parameter takes the name the operation's own path gives it; one the
            // operation's path has no placeholder for is not added
            if ($parameter->in === 'path' && $parameter->name !== null) {
                $placeholder = $placeholders[$parameter->name] ?? null;
                if ($placeholder === null) {
                    continue;
                }
                if ($placeholder !== $parameter->name) {
                    $parameter = self::copy($parameter);
                    $parameter->name = $placeholder;
                }
            }

            $key = $components->parameterKey($parameter);
            $existing = $this->findParameter($components, $operation->parameters, $key);

            // `null` is a reference to a component, which belongs to every operation using it
            // and is not changed for one of them
            if ($existing === false) {
                if ($this->isShared($hierarchy, $operation, $key)) {
                    continue;
                }

                $operation->parameters ??= [];
                $operation->parameters[] = self::copy($parameter);
            } elseif ($existing instanceof OA\Parameter && $parameter->schema instanceof Schema) {
                if (!$existing->schema instanceof Schema) {
                    $existing->schema = self::copy($parameter->schema);
                } elseif ($parameter->schema->pattern !== null) {
                    // the pattern is what the router matches, so the route's wins; the type is
                    // only guessed from it, so the attribute's stays
                    $existing->schema->pattern = $parameter->schema->pattern;
                }
            }
        }

        foreach ($route->responses ?? [] as $response) {
            $code = (string) $response->response;
            $range = substr($code, 0, 1) . 'XX';
            foreach ($operation->responses ?? [] as $existing) {
                if (in_array(strtoupper((string) $existing->response), [$code, $range], true)) {
                    continue 2;
                }
            }

            $operation->responses ??= [];
            $operation->responses[] = self::copy($response);
        }
    }

    /**
     * The candidate routes whose path agrees with one of an operation's: the same path where any
     * has it, or else one whose segments end with the operation's.
     *
     * @param list<int>    $candidates
     * @param list<string> $paths
     *
     * @return list<int>
     */
    private function agreeing(Routes $routes, array $candidates, array $paths): array
    {
        $exact = array_values(array_filter(
            $candidates,
            static fn (int $index): bool => in_array($routes->operation($index)->path, $paths, true),
        ));
        if ($exact !== []) {
            return $exact;
        }

        return array_values(array_filter(
            $candidates,
            static fn (int $index): bool => array_filter($paths, static fn (string $path): bool => self::endsWith((string) $routes->operation($index)->path, $path)) !== [],
        ));
    }

    /**
     * Whether a route's path ends with an operation's, segment by segment.
     *
     * Two segments agree where they are equal or the route's is a placeholder: the two may name a
     * placeholder differently, and an attribute may write the one value a route constrains its
     * placeholder to, such as `me`. A placeholder in the operation's path does not match a
     * literal in the route's, so `/settings/{id}` is not `/settings/metadata`. The route may have
     * more leading segments, which a server URL carries, but the operation may not have more
     * than the route.
     */
    private static function endsWith(string $routePath, string $operationPath): bool
    {
        $route = explode('/', trim($routePath, '/'));
        $operation = explode('/', trim($operationPath, '/'));

        if (count($operation) > count($route)) {
            return false;
        }

        foreach (array_reverse($operation) as $offset => $segment) {
            $theirs = $route[count($route) - 1 - $offset];
            if ($segment !== $theirs && !str_starts_with($theirs, '{')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The route's placeholder names mapped onto an operation's, by position.
     *
     * Both paths describe one endpoint, so where they have the same number of placeholders the
     * nth of one is the nth of the other, whatever each calls it: Laravel names one for route
     * model binding (`{event}`) or by type (`{integer}`), while the attribute says `{id}`. Where
     * the counts differ, only the names both paths use are mapped.
     *
     * @return array<string, string> route name → operation name
     */
    private function placeholderNames(string $operationPath, string $routePath): array
    {
        $ours = Routes::placeholdersOf($operationPath);
        $theirs = Routes::placeholdersOf($routePath);

        if (count($ours) === count($theirs)) {
            return array_combine($theirs, $ours);
        }

        $shared = array_values(array_intersect($theirs, $ours));

        return array_combine($shared, $shared);
    }

    /**
     * The parameter a list already has for a key: the inline one to fold into, `null` for a
     * reference to a component, which is left alone, or `false` for none.
     *
     * The key is swagger-php's, which reads a reference's name and location off its component,
     * and a missing name off the PHP parameter, since the augmenters that fill both in run later.
     *
     * @param list<OA\Parameter>|null $parameters
     */
    private function findParameter(ComponentIndex $components, ?array $parameters, ?string $key): OA\Parameter|false|null
    {
        if ($key === null) {
            return false;
        }

        foreach ($parameters ?? [] as $parameter) {
            if ($components->parameterKey($parameter) === $key) {
                return $parameter->ref !== null ? null : $parameter;
            }
        }

        return false;
    }

    /**
     * Whether the path items governing an operation's class declare a parameter.
     *
     * They are emitted at path level and apply to every operation under it. An operation-level
     * parameter of the same name and location would replace one of them whole, so the route's
     * would win over the attribute's.
     */
    private function isShared(PathItemHierarchy $hierarchy, OA\Operation $operation, ?string $key): bool
    {
        return $key !== null && isset($hierarchy->parametersFor($operation)[$key]);
    }

    /**
     * Whether attributes on the handler, rather than only the route, describe the operation.
     *
     * Any operation the translator did not add is described: attributes made it, including
     * classic ones, which hybrid mode assembles after the translator has run. The translator's
     * own operation carries no attributes, so for it this reads them off the method it sits on:
     * a bare `#[OA\Response]` there, or an `OA\Parameter` on one of its parameters, means
     * attributes contributed.
     */
    private function isDescribed(OA\Operation $operation): bool
    {
        if ($operation->getMeta(RouteTranslator::class) !== true) {
            return true;
        }

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
        $prefix = $hierarchy->prefixFor($operation);
        $path = (string) $operation->path;

        // a route outside its controller's prefix cannot be expressed under it; the path is left
        // as it is, and the pipeline still puts the prefix in front
        if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
            $operation->path = '/' . ltrim(substr($path, strlen($prefix)), '/');
        }
    }

    /**
     * A copy of what a route holds, so a build changes the copy and not the route kept for the next.
     *
     * @template T of OA\Operation|OA\Parameter|OA\Response|OA\Schema
     *
     * @param T $attribute
     *
     * @return T
     */
    private static function copy(OA\Operation|OA\Parameter|OA\Response|Schema $attribute): OA\Operation|OA\Parameter|OA\Response|Schema
    {
        // `clone` is shallow, and the children are what later augmenters change
        $copy = clone $attribute;

        if ($copy instanceof OA\Operation) {
            if ($copy->parameters !== null) {
                $copy->parameters = array_map(self::copy(...), $copy->parameters);
            }
            if ($copy->responses !== null) {
                $copy->responses = array_map(self::copy(...), $copy->responses);
            }
        } elseif ($copy instanceof OA\Parameter && $copy->schema instanceof Schema) {
            $copy->schema = self::copy($copy->schema);
        }

        return $copy;
    }
}
