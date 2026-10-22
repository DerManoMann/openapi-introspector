<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Routing\Laravel;

use Illuminate\Auth\Middleware\Authenticate;
use OpenApi\Spec as OA;

/**
 * Says which of a route's middleware authenticate, and with which guards.
 *
 * Laravel's names by default: the `auth` alias, with or without guards (`auth:sanctum,api`), and
 * the `Authenticate` middleware class or a subclass. A project with middleware of its own lists
 * it in `$names`, which replaces `auth`. A guard becomes a security scheme only where `$schemes`
 * maps it to one, since which scheme a guard means is the application's to say. A bare `auth`
 * uses the `$default` guard.
 * Extend for a project's own conventions.
 */
class Authentication
{
    /**
     * @param array<string, string> $schemes guard name → security scheme name, such as `['sanctum' => 'bearerAuth']`
     * @param string                $default the guard a bare `auth` uses, Laravel's own default unless the application changed it
     * @param list<string>          $names   middleware names or classes that authenticate
     */
    public function __construct(
        private readonly array $schemes = [],
        private readonly string $default = 'web',
        private readonly array $names = ['auth'],
    ) {
    }

    /**
     * The guards a route's middleware authenticates with, a bare `auth` counting as the default
     * guard, or `null` where none of it authenticates.
     *
     * @param iterable<mixed> $middleware
     *
     * @return list<string>|null
     */
    public function guards(iterable $middleware): ?array
    {
        $guards = null;
        foreach ($middleware as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            [$name, $parameters] = array_pad(explode(':', $entry, 2), 2, null);
            if (!in_array($name, $this->names, true) && !is_a($name, Authenticate::class, true)) {
                continue;
            }

            $guards ??= [];
            foreach ($parameters === null ? [$this->default] : explode(',', $parameters) as $guard) {
                $guards[] = trim($guard);
            }
        }

        return $guards === null ? null : array_values(array_unique($guards));
    }

    /**
     * One requirement per guard, since any of them lets the request through, or `null` unless
     * every guard maps to a scheme: naming only some would say the others are not accepted.
     *
     * @param list<string> $guards
     *
     * @return list<OA\Security\Requirement>|null
     */
    public function requirements(array $guards): ?array
    {
        $schemes = [];
        foreach ($guards as $guard) {
            $scheme = $this->schemes[$guard] ?? null;
            if ($scheme === null) {
                return null;
            }
            $schemes[$scheme] = true;
        }

        return array_map(static fn (string $scheme): OA\Security\Requirement => new OA\Security\Requirement(scheme: $scheme), array_keys($schemes)) ?: null;
    }
}
