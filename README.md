# openapi-introspector

[![Build Status](https://github.com/DerManoMann/openapi-introspector/actions/workflows/build.yml/badge.svg)](https://github.com/DerManoMann/openapi-introspector/actions/workflows/build.yml)
[![Coverage Status](https://coveralls.io/repos/github/DerManoMann/openapi-introspector/badge.svg)](https://coveralls.io/github/DerManoMann/openapi-introspector)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## Introduction

Your application already knows your API. The framework knows every route, with its method, path
and path parameters, and a handler whose docblock and signature describe it. A library such as
spatie/laravel-data knows the shape of the data going in and out. Documenting all of that with
[swagger-php](https://github.com/zircote/swagger-php) usually means writing it a second time, as
attributes, and keeping the two in step by hand. The documentation drifts the first time the code
changes and its attribute does not.

openapi-introspector reads what the framework and its libraries know instead, and contributes it to
the OpenAPI document swagger-php builds. Each source is a swagger-php plugin of its own:

| Plugin | Frameworks | Reads | Needs |
|---|---|---|---|
| `RoutesPlugin` | [Laravel](https://github.com/laravel/laravel), [Slim](https://github.com/slimphp/Slim) | the routes, and for Laravel their form requests and middleware | swagger-php 7.3 or later, the application with its routes registered: a booted Laravel application, or the Slim app's route collector |
| `LaravelDataPlugin` | [Laravel](https://github.com/laravel/laravel) | spatie/laravel-data objects | swagger-php 7.3 or later, a booted Laravel application |

The plugins work alone or together, and a description you have written as attributes wins over one
they read.

**Not in classic mode.** The package adds to the specification swagger-php builds in its spec and
hybrid modes. Classic mode builds none, so there is nothing for it to add to.

## Requirements

* PHP 8.2 or higher
* `zircote/swagger-php` 6.17 or higher; 7.3 or higher for the plugins, and 7.6 for plugin discovery

## Installation

```sh
composer require radebatz/openapi-introspector
```

## Routes

The routes come from the framework, and are merged with what the attributes describe:

* **Routes you have not documented still appear.** Each gets its path parameters, with a
  route constraint as the parameter's pattern. Where the route dispatches to a class method,
  it also gets a summary, description and parameters from that method, exactly as if it had
  been scanned.
* **Attributes you have written still win on description.** They are merged into the route's
  operation field by field, so you add them only for what a router cannot know: responses,
  request bodies, a better description.
* **Documentation without a route can be listed.** An inventory records every method and path, and
  says which are documented but not routed, a defect the document alone cannot show. Reading it
  needs the introspector registered directly, as in [With the inventory](#with-the-inventory).

This is the reverse of [openapi-router](https://github.com/DerManoMann/openapi-router), which
builds the routes from the attributes, and a project can use both.

### As a plugin

Given as an instance, `RoutesPlugin` takes adapters you have created:

```php
use Radebatz\OpenApi\Introspector\RoutesPlugin;
use Radebatz\OpenApi\Introspector\Routing\Laravel\LaravelAdapter;

$builder->withPlugins([new RoutesPlugin(new LaravelAdapter($router))]);
```

Named by its class, the plugin takes settings instead: each key is an adapter class, and its value
that adapter's settings, `[]` for its defaults. Only `LaravelAdapter` can be created from settings;
a Slim app passes a `SlimAdapter` instance, since its routes come from the app object. The settings
hold only class names and plain values, so they can live in a configuration file and survive
`php artisan config:cache`. `LaravelAdapter` reads
the routes from the running application when the document is built, those in the `api` middleware
group by default:

```php
$builder->withPlugins([
    RoutesPlugin::class => [LaravelAdapter::class => ['schemes' => ['sanctum' => 'bearerAuth']]],
]);
```

`LaravelAdapter`'s settings are listed in [Laravel: from settings](docs/laravel.md#from-settings).
L5-Swagger has no option for plugins, so the plugin is added through a
[`builder_factory`](docs/l5-swagger.md#with-the-plugin) of the project's own.

### With the inventory

`RoutesPlugin` does not expose the inventory. To read it, create a `RouteIntrospector` and register it
on the builder yourself. This also works on swagger-php before 7.3:

```php
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use Radebatz\OpenApi\Introspector\Routing\RouteIntrospector;
use Radebatz\OpenApi\Introspector\Routing\Laravel\LaravelAdapter;

$builder = (new Builder())
    ->setMode(Mode::SPEC)
    ->addSource(__DIR__ . '/app');

$introspector = (new RouteIntrospector())
    ->addAdapter(new LaravelAdapter($router));

$introspector->register($builder);
```

The build itself is unchanged. The inventory is available once it has run:

```php
$result = $builder->build();

$inventory = $introspector->inventory();
```

`inventory()` says, for each method and path, whether attributes, a route or both describe it.

## Data objects

`LaravelDataPlugin` describes [spatie/laravel-data](https://github.com/spatie/laravel-data) objects:

```php
use Radebatz\OpenApi\Introspector\LaravelDataPlugin;

$builder->withPlugins([LaravelDataPlugin::class]);
```

Each data class an operation uses becomes a schema, with its mapped names and the constraints of
its validation rules. A handler that takes one gets it as the request body, and one that returns
one gets it as the response. It works on controllers documented with attributes, and with
`RoutesPlugin` on the routes that plugin reads as well. The package declares it for swagger-php's
plugin discovery, so `$builder->withDiscoveredPlugins()` finds it from swagger-php 7.6.

## Documentation

Routes:

* [Routes](docs/routes.md) - where the routes come from, choosing them, and paths relative to a
  server
* [Adapters](docs/adapters.md) - what a route contributes, and the adapters' options
* [Laravel](docs/laravel.md) - model binding, form requests, authentication, other middleware,
  and the adapter from settings
* [What wins](docs/fold.md) - how routes and attributes fold together
* [The inventory](docs/inventory.md) - which endpoints are documented, routed, or both

Data objects:

* [Data objects](docs/laravel.md#data-objects) - what a data class becomes, and where its request
  and response differ

Integrations:

* [Generating for L5-Swagger](docs/l5-swagger.md)
* [Verifying responses](docs/verifying.md)

## Renamed in 1.5

The code is grouped by domain, then framework, and each plugin is named after what it contributes.
The old names still work, as deprecated aliases, and are removed in 2.0:

| Before 1.5 | Since 1.5 |
|---|---|
| `IntrospectorPlugin` | `RoutesPlugin` |
| `Introspector` | `Routing\RouteIntrospector` |
| `Adapters\LaravelAdapter` | `Routing\Laravel\LaravelAdapter` |
| `Adapters\SlimAdapter` | `Routing\Slim\SlimAdapter` |
| `AdapterInterface`, `ConfigurableAdapterInterface` | `Routing\AdapterInterface`, `Routing\ConfigurableAdapterInterface` |
| `Inventory`, `Inventory\Entry`, `Status` | `Routing\Inventory`, `Routing\Inventory\Entry`, `Routing\Status` |
| `RouteTranslator` | `Routing\RouteTranslator` |

## License

The MIT License (MIT). See [LICENSE](LICENSE).
