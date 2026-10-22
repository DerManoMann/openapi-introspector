# openapi-introspector

[![Build Status](https://github.com/DerManoMann/openapi-introspector/actions/workflows/build.yml/badge.svg)](https://github.com/DerManoMann/openapi-introspector/actions/workflows/build.yml)
[![Coverage Status](https://coveralls.io/repos/github/DerManoMann/openapi-introspector/badge.svg)](https://coveralls.io/github/DerManoMann/openapi-introspector)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## Introduction

Your framework already knows your API. Every route has a method, a path, path parameters
with their constraints, and a handler whose docblock and signature describe it. Documenting
that with [swagger-php](https://github.com/zircote/swagger-php) usually means writing it all a
second time, as attributes, and then keeping the two in step by hand. The documentation drifts
the first time a route changes and its attribute does not.

openapi-introspector reads the routes from the framework instead, and contributes them to the
OpenAPI document swagger-php builds:

* **Routes you have not documented still appear.** Each gets its path parameters, with a
  route constraint as the parameter's pattern. Where the route dispatches to a class method,
  it also gets a summary, description and parameters from that method, exactly as if it had
  been scanned.
* **Attributes you have written still win on description.** They fold into the route's
  operation field by field, so you add them only for what a router cannot know: responses,
  request bodies, a better description.
* **Documentation without a route is reported.** An inventory lists every method and path, and
  says which are documented but not routed. That is a defect the document alone cannot show.

This is the reverse of [openapi-router](https://github.com/DerManoMann/openapi-router), which
builds the routes from the attributes. The two are complements: openapi-router makes the
document the source of the routes, the introspector makes the routes the source of the
document, and a project can use both.

**Spec attributes only.** The package works inside swagger-php's spec pipeline, through
`Builder::withSpecification()`. Classic mode assembles no specification, so there is nothing
here for it to contribute to.

Supported frameworks:

* [Laravel](https://github.com/laravel/laravel)
* [Slim](https://github.com/slimphp/Slim)

## Requirements

* PHP 8.2 or higher
* `zircote/swagger-php` 6.17 or higher, for the `PathItem` chain helpers, `ComponentIndex::parameterKey()` and the `methods:` split

## Usage

```php
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Introspector;

$introspector = (new Introspector())
    ->addAdapter(new LaravelAdapter($router));

$result = $introspector
    ->register((new Builder())->setMode(Mode::SPEC)->addSource(__DIR__ . '/app'))
    ->build();

$inventory = $introspector->inventory();
```

An adapter yields one partial operation per method and path the framework routes. Where the
route dispatches to a class method, the operation carries that method's reflector, and the
pipeline derives the summary, the description and the parameters from it as it would for a
scanned one. A closure route is contributed bare and gets an id from its method and path.

### Where the routes come from

Hand the adapter the route table once the application has registered its routes.

**Laravel**: `app(Router::class)`, or `Route::getRoutes()` through the facade, after the
application has booted. An artisan command qualifies. A cached route table works the same,
because `CompiledRouteCollection` rebuilds `Route` objects when asked for them.

**Slim**: `$app->getRouteCollector()`, after the code that defines the routes has run and
before `$app->run()`.

### Choosing the routes

The whole table is rarely what a document should describe. A Laravel application also routes
Sanctum's CSRF cookie, Telescope, Horizon, Ignition and L5-Swagger's own documentation pages,
and every route the adapter sees becomes an `Introspected` entry in the inventory. Both adapters
also take any iterable of routes, so choosing them is an `array_filter` before the adapter
sees them.

Leaving out vendor routes needs no convention from the project. A route is vendor when its
handler is defined under `vendor/`, which is the test `php artisan route:list --except-vendor`
applies:

```php
use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\ViewController;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;

$vendor = base_path('vendor');

$routes = array_filter(
    app(Router::class)->getRoutes()->getRoutes(),
    static function (Route $route) use ($vendor): bool {
        $uses = $route->getAction('uses');

        if ($uses instanceof \Closure) {
            $file = (new \ReflectionFunction($uses))->getFileName();
        } elseif (is_string($uses) && !str_contains($uses, 'SerializableClosure')) {
            $class = ltrim($route->getControllerClass(), '\\');
            // Route::redirect() and Route::view() are the application's own routes
            if (in_array($class, [RedirectController::class, ViewController::class], true)) {
                return true;
            }
            $file = (new \ReflectionClass($class))->getFileName();
        } else {
            return true;
        }

        return !str_starts_with((string) $file, $vendor);
    },
);

$adapter = new LaravelAdapter($routes);
```

Narrower tests work the same way. In Laravel, `Route::middleware()` returns the route's
middleware, its group included, so the `api` group is one comparison. In Slim, a group's
prefix is part of each route's pattern:

```php
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Slim\Interfaces\RouteInterface;

// Laravel: the api middleware group
$routes = array_filter(
    app(Router::class)->getRoutes()->getRoutes(),
    static fn (Route $route): bool => in_array('api', $route->middleware(), true),
);

// Slim: everything under /api
$routes = array_filter(
    $app->getRouteCollector()->getRoutes(),
    static fn (RouteInterface $route): bool => str_starts_with($route->getPattern(), '/api'),
);
```

### Paths relative to a server

Attribute paths are often written relative to a server URL that carries a prefix: `/users` under
`https://example.com/api`, while the router serves `/api/users`. Both adapters take that prefix,
and take it off every route path that starts with it:

```php
$adapter = new LaravelAdapter($routes, prefix: '/api');
```

Only whole segments come off, so `/apis` keeps its path, and a route outside the prefix is left as
it is. The inventory then reports the same paths the document has.

### Generating for L5-Swagger

[L5-Swagger](https://github.com/DarkaOnLine/L5-Swagger) 11.2.1 and later take a
`builder_factory`, and this package has one. In `config/l5-swagger.php`, under `defaults`:

```php
'scanOptions' => [
    'builder_factory' => \Radebatz\OpenApi\Introspector\Integrations\L5Swagger\BuilderFactory::class,
    // ...
],
```

The factory documents the routes in the `api` middleware group, in hybrid mode, so the
attributes a project already has are read as they are.

The option takes a class name. Laravel serializes its config for `php artisan config:cache`,
which an object does not survive, so L5-Swagger creates the factory itself, with no arguments.
Nothing can be passed in.

Of L5-Swagger's own settings, the builder gets:

* the `annotations` paths, with the `pattern` and `exclude` scan options, as its sources
* `open_api_spec_version`
* the base path, as a server

`processors`, `analyser`, `analysis` and `default_processors_configuration` configure
L5-Swagger's classic generator and do not reach the builder.

Everything else is customised by subclassing, with the subclass's class name in the config:

```php
use Illuminate\Support\Facades\Route;
use OpenApi\Builder;
use Psr\Log\LoggerInterface;
use Radebatz\OpenApi\Introspector\Integrations\L5Swagger\BuilderFactory;

class ApiDocsFactory extends BuilderFactory
{
    protected function routes(): iterable
    {
        return array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with($route->uri(), 'api/v2/'),
        );
    }

    protected function builder(): Builder
    {
        return parent::builder()->setLogger(app(LoggerInterface::class));
    }
}
```

```php
'builder_factory' => \App\OpenApi\ApiDocsFactory::class,
```

The methods to override:

* `routes()` - the routes to document
* `mode()` - the build mode
* `builder()` - the `Builder` before the introspector is registered on it, for anything else
  it takes, such as a logger or augmenters
* `introspector()` - the introspector, for another adapter or a `prefix`

Before 11.2.1, L5-Swagger has no hook for a contribution, so the integration is an artisan
command that writes the file L5-Swagger serves:

```php
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Introspector;
use Radebatz\OpenApi\Introspector\Status;

final class GenerateApiDocs extends Command
{
    protected $signature = 'api-docs:generate';

    protected $description = 'Build the OpenAPI document from the attributes and the routes';

    public function handle(Router $router): int
    {
        $introspector = (new Introspector())->addAdapter(new LaravelAdapter($router));

        $introspector
            ->register((new Builder())->setMode(Mode::SPEC)->addSource(app_path()))
            ->build()
            ->saveAs(storage_path('api-docs/api-docs.json'));

        foreach ($introspector->inventory()->entries(Status::Unrouted) as $entry) {
            $this->warn("Documented but not routed: {$entry->method} {$entry->path}");
        }

        return self::SUCCESS;
    }
}
```

`storage_path('api-docs/api-docs.json')` is L5-Swagger's default `docs` path and `docs_json`
file. Keep `generate_always` off, which is the default: when it is on, L5-Swagger regenerates
the document on every request and overwrites this one. Pass the command a filtered list of
routes, as above, rather than the whole router.

### Verifying responses

The document says what each route returns.
[openapi-verifier](https://github.com/DerManoMann/openapi-verifier) checks that it does: each
response a test or a PSR-15 stack sees is validated against the schema the document gives it.
It has adapters for Laravel and Slim tests, PSR-15 middleware and Guzzle or PSR-18 clients.

Pass it the document the introspector built. Without one, the verifier generates its own from
the attributes alone. A handler that carries only `#[OA\Response]` gets its path from the route,
so its responses are missing from that document and are not checked.

In a Laravel test, with the file written above:

```php
use Radebatz\OpenApi\Verifier\Adapters\Laravel\OpenApiResponseVerifier;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use OpenApiResponseVerifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOpenApiVerifier(null, storage_path('api-docs/api-docs.json'));
    }
}
```

Generate the file before the tests run. Only responses the document has a schema for are
verified, so a route nothing but the router describes is skipped.

### What wins

The framework wins on existence and on dispatch facts: which paths and methods exist, and
what the router will match. Attributes win on description. In practice:

* Attributes on a route's handler add to what the route gives. A handler that serves one route
  and carries only `#[OA\Response]` gets the route's operation for the response to join. An
  operation the attributes declare without a path or method gets the route's, where one route
  matches its method. This needs the handler's class in the builder's sources, since only those
  are read for attributes.
* Where both describe a path parameter, it is folded by name and location. The attribute's
  description wins. An attribute parameter with no schema takes the route's, type included.
  One with a schema keeps it, except for `pattern`: the route's constraint is what the router
  matches, so it replaces the attribute's. A route with no constraint leaves the attribute's
  `pattern` as it is. A parameter referencing a component is left as it is, and so is one the
  controller declares on its `#[OA\PathItem(parameters: ...)]`, or a parent class does. The
  route adds no operation-level copy that would replace it, unless the attribute has its own to
  fold into.
* An operation declared on a method other than the handler, for the route's method and path,
  is completed the same way. The attribute operation is the base, so one that only adds a tag
  or a description still gets the route's path parameters.
* A route nothing describes is contributed by the adapter.
* A controller's `#[OA\PathItem(prefix: ...)]` is applied once. The route's full path already
  contains it, so it is taken off before the pipeline adds it again. A route whose path does
  not start with the prefix is left as it is, and still gets the prefix in front.
* A handler serving several routes, such as a Laravel route with an optional parameter, folds
  only the route its attribute names by method and path. The others are contributed whole, and
  a bare `#[OA\Response]` on such a handler joins none of them.
* A route with several methods on one path, such as Laravel's
  `Route::match(['get', 'post'], ...)`, is one route per method, all served by the same handler.
  Attributes that name no method, such as a bare `#[OA\Response]`, a parameter attribute or an
  `#[OA\Operation]` without `method`, describe every method. An `#[OA\Operation]` that sets
  `method` folds into the route for that method only.
* A route parameter's constraint, such as Laravel's `where('id', '[0-9]+')` or Slim's
  `{id:[0-9]+}`, becomes the parameter's `pattern`. The type is `integer` where the pattern
  admits nothing else. Otherwise it comes from the handler's parameter of the same name, if
  that is typed `int`, `float`, `string` or `bool`, and is `string` where it is not. A class
  type, such as a model Laravel binds from the route's value, says nothing about the value in
  the path and is not read.
* A Laravel route parameter bound to a model, such as `{post}` with `Post $post` in the
  handler's signature, adds a `404` response, which is what Laravel answers when no row matches.
  A parameter with a default gets none, since the default takes the place of a missing row. A
  `404` or `4XX` the attributes declare wins.
* `HEAD` is dropped as an artefact of `GET`, and a Laravel fallback route is not an endpoint.
* A route name becomes the `operationId` only when the adapter is asked to, with
  `nameAsOperationId: true`, because that is a convention and not a fact.

### The inventory

The second output. One entry per method and path, with a status:

| Status | Meaning |
| --- | --- |
| `Matched` | attributes describe it and a route serves it |
| `Introspected` | a route serves it and nothing else describes it; the adapter's operation went in |
| `Unrouted` | attributes describe it and no route serves it |
| `Conflict` | a route nothing describes, at a path attributes document for another route; it is left out |

`Unrouted` is the case worth acting on: a documented endpoint that is not routed is a defect in
one of the two, and the document alone cannot show it.

`Conflict` usually means attribute paths are written relative to a server URL (`/users` under
`https://example.com/api`), and a web route sits at the documented path. The documented operation
is kept, and the builder's logger gets a warning naming both. Choosing only the API routes avoids
it, with the adapter's `prefix` to match the server URL.

### Extending

Both adapters take a `Routing\Handler`, which resolves a route's handler to a method reflector,
and a `Routing\Constraint`, which turns a constraint into a schema. Each has a default, and
each is a plain class to extend for a project's own conventions.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
