# openapi-introspector

Reads a PHP framework's routes and contributes them to the OpenAPI document swagger-php builds,
so routes nobody has documented still appear, and documentation without a route is reported.

Terminology for this package. swagger-php's own vocabulary is defined in
[its CONTEXT.md](https://github.com/zircote/swagger-php/blob/master/CONTEXT.md) and is not
repeated here. Where a word belongs to both, theirs wins.

## Language

### Core concepts

**Route**:
One framework-level mapping of method and path to a handler. What this package reads.
_Avoid_: endpoint (an API-design word, not a framework one), path (means the URL alone)

**Operation**:
A swagger-php `Spec\Operation`. An adapter yields one per method and path a route serves,
carrying only what the route knows: the path, the method, the path parameters and the
handler's reflector. The pipeline fills in the rest.
_Avoid_: using it for the route itself

**Adapter**:
The per-framework reader that turns routes into operations, behind `AdapterInterface`. One
implementation per framework.
_Avoid_: contributor, driver, integration

**Integration**:
A ready-made class that plugs the introspector into another package's extension point, under
`Integrations\`, such as `Integrations\L5Swagger\BuilderFactory` for L5-Swagger's
`builder_factory`. It configures adapters, it does not read routes itself.
_Avoid_: bridge, plugin

**Handler**:
The callable a route dispatches to. `Routing\Handler` resolves it to the method reflector the
pipeline reads, and a path parameter to the method's parameter of the same name where its type
describes the path value. A closure has nothing to reflect on, so its route is contributed bare.
_Avoid_: action, controller (Laravel's words for one kind of handler)

**Constraint**:
A route parameter's regex, such as Laravel's `where()` or Slim's `{id:[0-9]+}`. It becomes the
parameter's `pattern`, and its `type` only where the regex admits nothing else.
_Avoid_: rule, requirement

**Prefix**:
The part of a route path that a server URL carries, taken off every route path that starts
with it, whole segments only.
_Avoid_: base path

### Outputs

**Inventory**:
The second output, beside the document. One entry per method and path, each with a
**status**.
_Avoid_: coverage, report

**Status**:
How an inventory entry's operation reached the document:
- `matched`: attributes describe it and a route serves it
- `introspected`: a route serves it and nothing else describes it
- `unrouted`: attributes describe it and no route serves it
- `conflict`: a route nothing describes, at a path attributes document for another route

_Avoid_: result, state

### swagger-php's seam

**Contribution**:
swagger-php's word for what `Builder::withSpecification()` hands in. Used only about that seam,
never about this package's classes.
_Avoid_: calling an adapter or an operation a contribution

**Fold**:
What happens when attributes and a route describe the same operation: the attribute wins on
description, and the route supplies what it knows, field by field.
_Avoid_: merge (swagger-php's mergers are a different step), override

## Relationships

- An **adapter** reads **routes** and yields an **operation** for each method and path
- The **handler** gives an operation its method reflector, and a path parameter its parameter
  reflector where the type is a scalar
- `Introspector::register()` adds the operations to a `Builder`, and the pipeline **folds**
  them with the attributes
- The **inventory** records each method and path with its **status**

## Flagged ambiguities

- "route" vs "operation": **resolved**. A **route** is the framework's input, an **operation**
  is what the document gets. This package turns the first into the second.
- "parameter type": **resolved**. What the **constraint** implies wins, because it is what the
  router matches. The handler's signature fills in only where the constraint implies nothing,
  and only for a scalar type. A class type is a binding, not a description of the path value.
