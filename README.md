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
* **Attributes you have written still win on description.** They fold into the route's
  operation field by field, so you add them only for what a router cannot know: responses,
  request bodies, a better description.
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
* `zircote/swagger-php` 6.17 or higher, for the `PathItem` chain helpers, `ComponentIndex::parameterKey()` and the `methods:` split

## Installation

```sh
composer require radebatz/openapi-introspector
```

## Usage

The builder is set up as usual and configured as needed - then the introspector asked to add the framework routes: 

```php
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Introspector;

// create Builder as per usual
$builder = (new Builder())
    ->setMode(Mode::SPEC)
    ->addSource(__DIR__ . '/app');

// configure the introspector and add to the builder
$introspector = (new Introspector())
    ->addAdapter(new LaravelAdapter($router));

$introspector->register($builder);
```

The build itself is unchanged. The inventory is available once it has run:

```php
$result = $builder->build();

$inventory = $introspector->inventory();
```

The adapter reads the framework's routes, and the introspector folds them into the document the
builder assembles. `inventory()` then says, for each method and path, whether attributes, a route
or both describe it.

## Documentation

* [Routes](docs/routes.md) - where the routes come from, choosing them, and paths relative to a
  server
* [Adapters](docs/adapters.md) - what a route contributes, and the adapters' options
* [Laravel](docs/laravel.md) - model binding, form requests, authentication and other middleware
* [What wins](docs/fold.md) - how routes and attributes fold together
* [The inventory](docs/inventory.md) - which endpoints are documented, routed, or both
* [Generating for L5-Swagger](docs/l5-swagger.md)
* [Verifying responses](docs/verifying.md)

## License

The MIT License (MIT). See [LICENSE](LICENSE).
