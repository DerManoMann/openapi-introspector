# Adapters

An adapter reads a framework's route table and yields one partial operation per method and path
it routes. The introspector folds those into the document the builder assembles, as described in
[What wins](fold.md).

```php
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Adapters\SlimAdapter;

new LaravelAdapter($router);
new SlimAdapter($app->getRouteCollector());
```

## Adapter options

Every option has a default, so the routes are the only argument an adapter needs. The others are
best passed by name.

| Option | Default | Adapters | |
| --- | --- | --- | --- |
| `routes` | | both | the route table, or any iterable of routes, see [Routes](routes.md) |
| `nameAsOperationId` | `false` | both | [Operation ids](#operation-ids) |
| `handler` | `new Handler()` | both | [Handlers](#handlers) |
| `constraint` | `new Constraint()` | both | [Constraints](#constraints) |
| `prefix` | `''` | both | [Paths relative to a server](routes.md#paths-relative-to-a-server) |
| `rules` | `new ValidationRules()` | Laravel | [Form requests](laravel.md#form-requests) |
| `logger` | `null` | Laravel | [Form requests](laravel.md#form-requests) |
| `authentication` | `new Authentication()` | Laravel | [Authentication](laravel.md#authentication) |

`Handler` and `Constraint` are in `Radebatz\OpenApi\Introspector\Routing`, `ValidationRules` and
`Authentication` in `Radebatz\OpenApi\Introspector\Routing\Laravel`.

## What a route contributes

* One operation per method and path. A route with several methods on one path, such as Laravel's
  `Route::match(['get', 'post'], ...)`, is one operation per method, all served by the same
  handler. `HEAD` is dropped as an artefact of `GET`.
* Its path parameters, each with a schema from the route's [constraint](#constraints).
* Where the route dispatches to a class method, that method's reflector. The pipeline derives the
  summary, the description and the parameters from it, as it would for a scanned one. A closure
  route is contributed bare and gets an id from its method and path.

The Laravel adapter contributes more from what Laravel itself does with a route: see
[Laravel](laravel.md).

## Operation ids

A route name becomes the `operationId` only when the adapter is asked to, because that is a
convention and not a fact:

```php
new LaravelAdapter($router, nameAsOperationId: true);
```

A route without a name gets the id swagger-php derives, as without the option.

## Handlers

A `Handler` resolves a route's handler to the method reflector the pipeline derives from. It
reads the callable forms the routers accept: `Class:method`, `Class::method`, `Class@method`,
`[Class, 'method']`, `[$object, 'method']`, and an invokable class name or instance. A closure
yields no method.

A route parameter gets the handler's parameter of the same name, so the pipeline reads its type,
but only for a scalar. A class type, such as a model Laravel binds from the route's value, says
nothing about the value in the path and is not read.

Extend it for a project's own handler conventions, such as a command class:

```php
use Radebatz\OpenApi\Introspector\Routing\Handler;

final class CommandHandler extends Handler
{
    public function reflectCallable(mixed $callable): ?\ReflectionMethod
    {
        if (is_string($callable) && str_ends_with($callable, 'Command')) {
            return $this->reflect($callable, 'handle');
        }

        return parent::reflectCallable($callable);
    }
}

new LaravelAdapter($router, handler: new CommandHandler());
```

## Constraints

A route parameter's constraint, such as Laravel's `where('id', '[0-9]+')` or Slim's
`{id:[0-9]+}`, becomes the parameter's `pattern`. A `Constraint` turns it into a schema:

* The type is `integer` where the pattern admits nothing else.
* Otherwise the type comes from the handler's parameter of the same name, if that is typed `int`,
  `float`, `string` or `bool`.
* Otherwise it is `string`.

The patterns read as integers are a list on the class. A subclass replaces it, or overrides
`type()` for anything else:

```php
use Radebatz\OpenApi\Introspector\Routing\Constraint;

final class ProjectConstraint extends Constraint
{
    protected array $integer = ['[0-9]+', '\d+', '[1-9][0-9]*'];
}

new LaravelAdapter($router, constraint: new ProjectConstraint());
```
