# Generating for L5-Swagger

[L5-Swagger](https://github.com/DarkaOnLine/L5-Swagger) 11.2.1 and later take a
`builder_factory`, and this package has one. In `config/l5-swagger.php`, under `defaults`:

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

## Customising the factory

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
the document on every request and overwrites this one. Pass the command a
[filtered list of routes](routes.md#choosing-the-routes) rather than the whole router.
