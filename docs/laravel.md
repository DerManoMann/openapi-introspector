# Laravel

Besides what [any route contributes](adapters.md#what-a-route-contributes), the Laravel adapter
reads what Laravel itself does with a route before the handler runs: binding a model, validating
a form request, authenticating, authorizing. Each adds the response Laravel answers with when that step fails.
A response the attributes declare for the same status code wins, as does a range such as `4XX`.

## Routes

* A route with optional parameters serves one path per combination present, so it is contributed
  as that many operations.
* A fallback route is not an endpoint and is skipped.

## Model binding

A route parameter bound to a model, such as `{post}` with `Post $post` in the handler's
signature, adds a `404` response. That is what Laravel answers when no row matches. The parameter
matches by name, or by its snake case form, as Laravel's implicit binding does.

A parameter with a default gets none, since the default takes the place of a missing row.

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

The [L5-Swagger factory](l5-swagger.md) sets `default` from the application's
`auth.defaults.guard`.

## Authorization

A route behind authorization middleware gets a `403` response. The middleware is read the same
way as for [authentication](#authentication). Authorization middleware is:

* the `can` alias, with its ability and models, such as `can:update,post`
* the `Authorize` class, or a subclass, as `Authorize::using()` names it
* Sanctum's token ability middleware, by the `abilities` and `ability` aliases or by class

Whether the gate denies depends on the user, and often on the row. The `403` says the route can
answer with one, not when.

The adapter's `authorization` option lists the middleware that authorizes, for a project with
middleware of its own. The list replaces the default names. The `Authorize` class always counts:

```php
use Radebatz\OpenApi\Introspector\Routing\Laravel\Authorization;

new LaravelAdapter($router, authorization: new Authorization(names: ['can', 'role']));
```

A form request's `authorize()` adds no `403`. Telling a real check from a plain `return true`
would mean calling it, and outside a request there is no user to call it for.

A `403` the attributes declare wins.
