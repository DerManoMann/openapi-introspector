# openapi-introspector

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## Introduction

Reads what a PHP framework already knows about the API it serves and contributes it to the
OpenAPI document [swagger-php](https://github.com/zircote/swagger-php) builds, so a route no
attribute describes still appears, and a documented path no route serves is noticed.

This is the reverse of [openapi-router](https://github.com/DerManoMann/openapi-router), which
configures the router from the attributes. The two are complements: the introspector covers what
exists, openapi-router drives routing from what is declared, and a project can use both.

**Spec attributes only.** The package works inside swagger-php's spec pipeline, through
`Builder::withSpecification()`. Classic mode assembles no specification, so there is nothing
here for it to contribute to.

Supported frameworks:

* [Laravel](https://github.com/laravel/laravel)
* [Slim](https://github.com/slimphp/Slim)

## Requirements

* PHP 8.2 or higher
* `zircote/swagger-php` with `Builder::withSpecification()` — merged upstream
  ([#2218](https://github.com/zircote/swagger-php/pull/2218)) but not yet in a release, so this
  package requires `dev-master` until a 6.x minor carries it

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
