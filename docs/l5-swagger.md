# Generating for L5-Swagger

[L5-Swagger](https://github.com/DarkaOnLine/L5-Swagger) runs this package in one of two ways:

* [with the `plugins` option](#with-the-plugins-option), from L5-Swagger 11.4.0: the plugins are named
  in the config, with their settings
* [with the factory](#with-the-factory), from L5-Swagger 11.2.1: the `builder_factory` option takes
  this package's factory, which can be customised in code

## With the `plugins` option

In `config/l5-swagger.php`, under `defaults`:

```php
'scanOptions' => [
    'mode' => 'hybrid',
    'plugins' => [
        \Radebatz\OpenApi\Introspector\RoutesPlugin::class => [
            \Radebatz\OpenApi\Introspector\Routing\Laravel\LaravelAdapter::class => [
                'schemes' => ['sanctum' => 'bearerAuth'],
            ],
        ],
        \Radebatz\OpenApi\Introspector\LaravelDataPlugin::class,
    ],
],
```

This needs swagger-php 7.3 or later, and `mode` set to `hybrid` or `spec`. Plugins do not run in
classic mode, which is L5-Swagger's default. `LaravelDataPlugin` is only for a project using
[spatie/laravel-data](https://github.com/spatie/laravel-data).

`LaravelAdapter`'s settings are listed in [Laravel: from settings](laravel.md#from-settings). They
hold only class names and plain values, so the config survives `php artisan config:cache`. A bare
`auth` middleware uses the application's `auth.defaults.guard` as its
[guard](laravel.md#authentication).

The plugins add to the build L5-Swagger configures, so its `annotations` paths, the `pattern` and
`exclude` scan options and `default_processors_configuration` all apply. Anything the settings cannot express, such as a
logger, needs [the factory](#customising-the-factory).

## With the factory

L5-Swagger 11.2.1 and later take a `builder_factory`, and this package has one. In
`config/l5-swagger.php`, under `defaults`:

```php
'scanOptions' => [
    'builder_factory' => \Radebatz\OpenApi\Introspector\Integrations\L5Swagger\BuilderFactory::class,
    // ...
],
```

The factory documents the routes in the `api` middleware group, in hybrid mode, so the
attributes a project already has are read as they are. A bare `auth` middleware uses the
application's `auth.defaults.guard` as its [guard](laravel.md#authentication).

The option takes a class name. Laravel serializes its config for `php artisan config:cache`,
which an object does not survive, so L5-Swagger creates the factory itself, with no arguments.
Nothing can be passed in.

Of L5-Swagger's own settings, the builder gets:

* the `annotations` paths, with the `pattern` and `exclude` scan options, as its sources
* `open_api_spec_version`
* the base path, as a server

`processors`, `analyser`, `analysis` and `default_processors_configuration` configure
L5-Swagger's classic generator and do not reach the builder.

### Customising the factory

Everything else is customised by subclassing, with the subclass's class name in the config:

```php
use Illuminate\Support\Facades\Route;
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

    protected function schemes(): array
    {
        return ['sanctum' => 'bearerAuth'];
    }

    protected function logger(): ?LoggerInterface
    {
        return app(LoggerInterface::class);
    }
}
```

```php
'builder_factory' => \App\OpenApi\ApiDocsFactory::class,
```

The methods to override:

* `routes()` - the routes to document
* `mode()` - the build mode
* `schemes()` - the security scheme each authentication guard stands for, none by default
* `logger()` - where the builder and the adapter report, nowhere by default
* `builder()` - the `Builder` before the introspector is registered on it, for anything else
  it takes, such as augmenters
* `introspector()` - the introspector, for another adapter or a `prefix`

## Before 11.2.1

Before 11.2.1, L5-Swagger has no hook for a contribution, so the integration is an artisan
command that writes the file L5-Swagger serves:

```php
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use Radebatz\OpenApi\Introspector\Routing\Laravel\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Routing\RouteIntrospector;
use Radebatz\OpenApi\Introspector\Routing\Status;

final class GenerateApiDocs extends Command
{
    protected $signature = 'api-docs:generate';

    protected $description = 'Build the OpenAPI document from the attributes and the routes';

    public function handle(Router $router): int
    {
        $introspector = (new RouteIntrospector())->addAdapter(new LaravelAdapter($router));

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
the document on every request and overwrites this one. Pass the command a
[filtered list of routes](routes.md#choosing-the-routes) rather than the whole router.
