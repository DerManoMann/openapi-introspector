<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Routing\Laravel;

use Illuminate\Auth\Middleware\Authorize;

/**
 * Says whether any of a route's middleware authorizes, and so can answer 403.
 *
 * Laravel's `can` alias by default, with its ability and models (`can:update,post`), and the
 * `Authorize` middleware class or a subclass. Sanctum's token ability middleware counts too, by
 * the aliases its documentation registers it under and by class. A project with middleware of
 * its own lists it in `$names`, which replaces these. Whether the gate denies is not known here:
 * the 403 is a response the route can give.
 * Extend for a project's own conventions.
 */
class Authorization
{
    /**
     * @param list<string> $names middleware names or classes that authorize
     */
    public function __construct(
        private readonly array $names = [
            'can',
            'abilities',
            'ability',
            'Laravel\Sanctum\Http\Middleware\CheckAbilities',
            'Laravel\Sanctum\Http\Middleware\CheckForAnyAbility',
        ],
    ) {
    }

    /**
     * @param iterable<mixed> $middleware
     */
    public function authorizes(iterable $middleware): bool
    {
        foreach ($middleware as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $name = explode(':', $entry, 2)[0];
            if (in_array($name, $this->names, true) || is_a($name, Authorize::class, true)) {
                return true;
            }
        }

        return false;
    }
}
