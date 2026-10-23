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
use Radebatz\OpenApi\Introspector\Status;

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
