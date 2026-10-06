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
* **Attributes you have written still win.** Where attributes describe a route, they are kept
  whole, so you add them only for what a router cannot know: responses, request bodies, a
  better description.
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
* `zircote/swagger-php` 6.12 or higher, the first release with `Builder::withSpecification()`

## Usage

```php
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Introspector;

$introspector = (new Introspector())
    ->withAdapter(new LaravelAdapter($router));

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

### Generating for L5-Swagger

[L5-Swagger](https://github.com/DarkaOnLine/L5-Swagger) builds its document itself, with no
hook for a contribution, so for now the integration is an artisan command that writes the file
L5-Swagger serves:

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
        $introspector = (new Introspector())->withAdapter(new LaravelAdapter($router));

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

### What wins

The framework wins on existence and on dispatch facts: which paths and methods exist, and
what the router will match. Attributes win on description. In practice:

* A route attributes already describe is left to the attributes. The adapter's operation
  stands aside whole, because the pipeline cannot yet fold two halves of one operation.
* A route nothing describes is contributed by the adapter.
* A route parameter's constraint, such as Laravel's `where('id', '[0-9]+')` or Slim's
  `{id:[0-9]+}`, becomes the parameter's `pattern`. The type is `integer` only where the
  pattern admits nothing else, and `string` otherwise.
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

`Unrouted` is the case worth acting on: a documented endpoint that is not routed is a defect in
one of the two, and the document alone cannot show it.

### Extending

Both adapters take a `Routing\Handler`, which resolves a route's handler to a method reflector,
and a `Routing\Constraint`, which turns a constraint into a schema. Each has a default, and
each is a plain class to extend for a project's own conventions.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
