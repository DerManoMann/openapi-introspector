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
