# The inventory

The document is the builder's output. The inventory is the introspector's own: a summary of what
the adapters' routes and the attributes added up to.

It has one entry per method and path the application routes or documents. Each says if or how
that operation reached the document: from a route, from attributes, from both, or not at all.
Where a route was involved, the entry also names the adapter that yielded it.

The inventory is recorded while the build runs. In the finished document, an operation the
framework routes and one only attributes describe look the same, so it cannot be read off there.
It is available once the build has run:

```php
use Radebatz\OpenApi\Introspector\Routing\Status;

$introspector->register($builder)->build();

foreach ($introspector->inventory()->entries(Status::Unrouted) as $entry) {
    echo "Documented but not routed: {$entry->method} {$entry->path}\n";
}
```

| Status | Meaning |
| --- | --- |
| `Matched` | attributes describe it and a route serves it |
| `Introspected` | a route serves it and nothing else describes it, so the adapter's operation went in |
| `Unrouted` | attributes describe it and no route serves it |
| `Conflict` | a route nothing describes, at a path the attributes document for another route. The route is left out |

`Unrouted` is the case worth acting on: a documented endpoint that is not routed is a defect in
one of the two, and the document alone cannot show it.

`Conflict` usually means attribute paths are written relative to a server URL (`/users` under
`https://example.com/api`), and a web route sits at the documented path. The documented operation
is kept, and the builder's logger gets a warning naming both.
[Choosing](routes.md#choosing-the-routes) only the API routes avoids it, with the adapter's
[`prefix`](routes.md#paths-relative-to-a-server) to match the server URL.

## From the command line

In a Laravel application, the package registers an artisan command that builds the document and
prints its inventory. `route:list` already lists the routes, so by default the command lists only
the entries that need attention, `unrouted` and `conflict`, and counts the rest:

```
$ php artisan openapi:inventory
+--------+---------------------+----------+---------+
| Method | Path                | Status   | Adapter |
+--------+---------------------+----------+---------+
| GET    | /api/orders/archive | unrouted |         |
+--------+---------------------+----------+---------+
matched: 1, introspected: 1, unrouted: 1, conflict: 0
```

The options:

* `--all` - list every entry
* `--status=<status>` - list only entries with this status, repeatable
* `--format=json` - print the entries as JSON, each as `Entry::toArray()` gives it. JSON lists every
  entry, since a script reading it wants them all, and `--status` narrows it
* `--fail-on=<status>` - exit with `1` when an entry has this status, repeatable
* `--documentation=<name>` - the L5-Swagger documentation to follow, its default where not given

It exits with `2` when an option or the configuration is invalid, or when the build logged an error
before the inventory was recorded, such as a class it could not load: operations may then be
missing, and the check cannot be trusted. swagger-php logs a class it could not load as an error
from 7.5, and as a warning before, which the command does not count. Messages and the build's log go to the error output, so
the JSON on the standard output stays parseable.

**What the build reads.** With L5-Swagger installed, the command follows a documentation in
`config/l5-swagger.php`:

* its `annotations` paths, with the `pattern` and `exclude` scan options, as the sources
* its `constants`, defined before the scan as L5-Swagger defines them
* its `mode`, where that is `spec`, and hybrid otherwise, since classic mode builds no specification
  to add to
* its `analyser` scan option, or attributes only as L5-Swagger reads them, so docblock annotations
  it ignores are not counted either
* the `LaravelAdapter` settings its `plugins` option gives `RoutesPlugin`, so the same routes are
  read

Where L5-Swagger's own build does not add the routes, in classic mode or with no `RoutesPlugin` in
`plugins`, the document it serves has none of them. The command then says so, and the inventory
shows what adding them would give. With the `plugins` option, L5-Swagger turns the warning a conflict
logs into an error, so its own generation fails, and a CI check wants `--fail-on conflict` as well as
`--fail-on unrouted`.

Without L5-Swagger, it reads the `app/` directory in hybrid mode. Either way, without adapter
settings, it reads the routes in the `api` middleware group. A
[`builder_factory`](l5-swagger.md#with-the-factory) subclass that chooses other routes is not
followed: the command reads its routes from settings, which a factory does not have.
