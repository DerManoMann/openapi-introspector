# What wins

The framework wins on existence and on dispatch facts: which paths and methods exist, and what
the router will match. Attributes win on description.

* Attributes on a route's handler add to what the route gives. A handler that serves one route
  and carries only `#[OA\Response]` gets the route's operation for the response to join. An
  operation the attributes declare without a path or method gets the route's, where one route
  matches its method. This needs the handler's class in the builder's sources, since only those
  are read for attributes.
* An operation declared on a method other than the handler, for the route's method and path, is
  completed the same way. The attribute operation is the base, so one that only adds a tag or a
  description still gets the route's path parameters.
* A route nothing describes is contributed by the adapter.
* From the route, the attribute operation takes what it does not set itself: the `operationId`,
  the request body, the security requirements, and a response for each status code it has none
  for. A range it declares, such as `4XX`, counts for the codes it covers.

## Path parameters

Where both describe a path parameter, it is folded by name and location.

* The attribute's description wins.
* An attribute parameter with no schema takes the route's, type included.
* One with a schema keeps it, except for `pattern`. The route's constraint is what the router
  matches, so it replaces the attribute's. A route with no constraint leaves the attribute's
  `pattern` as it is.
* A parameter referencing a component is left as it is, and so is one the controller declares on
  its `#[OA\PathItem(parameters: ...)]`, or a parent class does. The route adds no
  operation-level copy that would replace it, unless the attribute has its own to fold into.

## Prefixes

A controller's `#[OA\PathItem(prefix: ...)]` is applied once. The route's full path already
contains it, so it is taken off before the pipeline adds it again. A route whose path does not
start with the prefix is left as it is, and still gets the prefix in front.

## Handlers serving several routes

* A handler serving several routes, such as a Laravel route with an optional parameter, folds only
  the route its attribute names by method and path. The others are contributed whole, and a bare
  `#[OA\Response]` on such a handler joins none of them.
* A route with several methods on one path is one route per method. Attributes that name no
  method, such as a bare `#[OA\Response]`, a parameter attribute or an `#[OA\Operation]` without
  `method`, describe every method. An `#[OA\Operation]` that sets `method` folds into the route
  for that method only.
