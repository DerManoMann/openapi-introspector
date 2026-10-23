# Routes

## Where the routes come from

Hand the adapter the route table once the application has registered its routes.

**Laravel**: `app(Router::class)`, or `Route::getRoutes()` through the facade, after the
application has booted. An artisan command qualifies. A cached route table works the same,
because `CompiledRouteCollection` rebuilds `Route` objects when asked for them.

**Slim**: `$app->getRouteCollector()`, after the code that defines the routes has run and
before `$app->run()`.

## Choosing the routes

The whole table is rarely what a document should describe. A Laravel application also routes
Sanctum's CSRF cookie, Telescope, Horizon, Ignition and L5-Swagger's own documentation pages,
and every route the adapter sees becomes an `Introspected` entry in the [inventory](inventory.md).
Both adapters also take any iterable of routes, so choosing them is an `array_filter` before the
adapter sees them.

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

## Paths relative to a server

Attribute paths are often written relative to a server URL that carries a prefix: `/users` under
`https://example.com/api`, while the router serves `/api/users`. Both adapters take that prefix,
and take it off every route path that starts with it:

```php
$adapter = new LaravelAdapter($routes, prefix: '/api');
```

Only whole segments come off, so `/apis` keeps its path, and a route outside the prefix is left as
it is. The inventory then reports the same paths the document has.
