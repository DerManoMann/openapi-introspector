# Laravel

Besides what [any route contributes](adapters.md#what-a-route-contributes), the Laravel adapter
reads what Laravel itself does with a route before the handler runs: binding a model, validating
a form request, and the route's middleware. Each adds the response Laravel answers with when that
step fails.
A response the attributes declare for the same status code wins, as does a range such as `4XX`.

## Routes

* A route with optional parameters serves one path per combination present, so it is contributed
  as that many operations.
* A fallback route is not an endpoint and is skipped.

## Model binding

A route parameter bound to a model, such as `{post}` with `Post $post` in the handler's
signature, adds a `404` response. That is what Laravel answers when no row matches. The parameter
matches by name, or by its snake case form, as Laravel's implicit binding does.

A default does not change that. Laravel skips binding only where an optional placeholder is
absent from the URL, and the adapter contributes that path without the placeholder, so it gets
no `404`. The path with the placeholder still does.

## Form requests

A handler that takes a form request gets its input from the request's `rules()`:

* a request body for `POST`, `PUT` and `PATCH`, query parameters otherwise
* a `422` response

Only rules with a plain schema equivalent are read: `required`, `nullable`, the type rules
(`string`, `integer`, `numeric`, `boolean`, `array`, `date`), `min`, `max`, `between`, `in`,
`email`, `url` and `uuid`. Dotted keys such as `items.*.id` nest. Any other rule leaves the field
as it is. A request body the attributes declare wins.

The translation is the adapter's `rules` option, a `ValidationRules`, which can be extended for a
project's own rules.

`rules()` is called on a bare instance, outside a request. One that needs the user or the route
cannot be read, and the operation then gets only the `422`. The adapter's `logger` says why:

```php
new LaravelAdapter($router, logger: app(LoggerInterface::class));
```

## Data objects

[spatie/laravel-data](https://github.com/spatie/laravel-data) objects are described by
`LaravelDataPlugin`. It works without `IntrospectorPlugin`, on controllers documented with
attributes, and with it on the routes that plugin reads as well:

```php
use Radebatz\OpenApi\Introspector\LaravelDataPlugin;

$builder->withPlugins([LaravelDataPlugin::class]);
```

The package declares it for swagger-php's plugin discovery, so with swagger-php 7.6 or later
`$builder->withDiscoveredPlugins()` finds it as well. It takes no settings, and does nothing where
laravel-data is not installed.

A handler that takes a data object gets the following, whether `IntrospectorPlugin` reads it from a
route or it carries OpenApi attributes:

* the data object as its request body, and a `422` response, since laravel-data validates it first
* a `200` response with the data object, where the handler's return type is a data class

A request body or a `2xx` response the operation already has wins. The `422` is added either way.

Each data class an operation uses becomes a component, and so does each data class it holds, as a
property or in a collection:

* the properties are the ones the object outputs, under their mapped names, without the
  `#[Hidden]` ones
* the types come from the PHP types, so a nested data object is a reference to its component
* the constraints and `required` come from the rules laravel-data validates the class with, read
  the same way as a [form request's rules](#form-requests). A rule with no schema equivalent, such
  as `exists`, is left out

Where the request and the response differ, through mapped names, `#[Hidden]` or `#[Computed]`, the
request body references a second component for the input: the properties laravel-data reads,
under their input names, without the computed ones.

The data classes are read through laravel-data's configuration in Laravel's container, so the
application has to be booted, as it is in an artisan command. Where it is not, a data class is not
described, and swagger-php's logger gets a warning naming the class.

## Authentication

A route behind authentication middleware gets a `401` response. The middleware is the route's
and its controller's, less what the route excludes. Authentication middleware is:

* the `auth` alias, bare or with guards, such as `auth:sanctum,api`
* the `Authenticate` class, or a subclass

Which security scheme a guard stands for is the application's to say. The adapter's
`authentication` option maps guards to the schemes the document declares:

```php
use Radebatz\OpenApi\Introspector\Routing\Laravel\Authentication;

new LaravelAdapter($router, authentication: new Authentication(
    schemes: ['sanctum' => 'bearerAuth'],
    default: 'sanctum',
));
```

* `schemes` - the security scheme each guard stands for, none by default
* `default` - the guard a bare `auth` uses, `web` unless given
* `names` - the middleware that authenticates, `['auth']` by default, for a project with
  middleware of its own, in place of `auth`

A route gets `security` only where every guard on it maps to a scheme, with one requirement per
guard, since any of them lets the request through. Naming only some would say the others are not
accepted. A route with a guard that does not map still gets the `401`.

The attributes' `security` wins, including an empty one for a public operation, and so does their
`401`.

Passport's scope middleware adds scopes to the requirements:

* `scopes:read,write` needs all of them, so each requirement gets both
* `scope:read,write` needs any one, so each scope gets a requirement of its own

The [L5-Swagger factory](l5-swagger.md) sets `default` from the application's
`auth.defaults.guard`.

## Other middleware

The route's other middleware, read the same way as for [authentication](#authentication), adds
the status codes it can answer with:

| Middleware | Response |
| --- | --- |
| `can`, such as `can:update,post`, and the `Authorize` class | `403` |
| `verified` | `403` |
| `signed` | `403` |
| `password.confirm` | `423` |
| `throttle` | `429` |
| Sanctum's `abilities` and `ability` | `403` |
| Passport's `scopes` and `scope` | `403` |
| spatie/laravel-permission's `role`, `permission` and `role_or_permission` | `403` |

Laravel's own middleware counts by its alias and by its class, a subclass included. Sanctum's,
Passport's and spatie's count by their aliases and by their class names, without the packages
being installed. `verified` redirects a request that does not expect JSON. The table gives the
API's answer.

Whether a gate denies, or a limit is reached, depends on the request. The status says the route
can answer with one, not when.

The adapter's `middleware` option holds the map. A project with middleware of its own extends
the defaults, since a map given replaces them:

```php
use Radebatz\OpenApi\Introspector\Routing\Laravel\Middleware;
use Symfony\Component\HttpFoundation\Response;

new LaravelAdapter($router, middleware: new Middleware([
    ...Middleware::DEFAULTS,
    'tenant' => Response::HTTP_NOT_FOUND,
]));
```

A form request's `authorize()` adds no `403`. Telling a real check from a plain `return true`
would mean calling it, and outside a request there is no user to call it for.

A response the attributes declare for the same status code wins.

## From settings

`LaravelAdapter::fromConfig()` creates the adapter from plain settings, reading the routes from the
application's container. That is what [`IntrospectorPlugin`](../README.md#as-a-swagger-php-plugin)
uses when its settings name `LaravelAdapter`. The settings, all optional:

* `middleware` - the middleware group whose routes are documented, or a list of groups, `api` by
  default. With a list, a route in any of them is documented
* `schemes` - the security scheme each guard stands for, such as `['sanctum' => 'bearerAuth']`,
  none by default
* `defaultGuard` - the guard a bare `auth` uses, the application's `auth.defaults.guard` by
  default, `web` where it sets none
* `nameAsOperationId` - use route names as operation ids, `false` by default
* `routeSource` - where the routes and the default guard come from, the class name of
  `Routing\Laravel\RouteSource` or a subclass, created with no arguments

A setting it does not know, or a value it cannot use, throws.

The routes and the default guard are read when the document is built, not when the adapter is
created. A builder configured in a service provider's `register()`, before the routes exist,
still documents them.

The settings hold only class names and plain values, so the configuration survives
`php artisan config:cache`. A subclass of `RouteSource` changes which routes are read, such as
only those of one API version:

```php
use Illuminate\Routing\Route;
use Radebatz\OpenApi\Introspector\Routing\Laravel\RouteSource;

class V2Routes extends RouteSource
{
    public function routes(array $middleware): array
    {
        return array_values(array_filter(
            parent::routes($middleware),
            static fn (Route $route): bool => str_starts_with($route->uri(), 'api/v2/'),
        ));
    }
}
```

```php
IntrospectorPlugin::class => [LaravelAdapter::class => ['routeSource' => V2Routes::class]],
```

Anything beyond these, such as a `prefix` or middleware of a project's own, needs the adapter
created in code.
