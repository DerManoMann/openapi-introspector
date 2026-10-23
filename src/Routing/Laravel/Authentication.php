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
 *
 * Passport's scope middleware puts scopes on the requirements: `scopes:a,b`, which needs all of
 * them, on each requirement, and `scope:a,b`, which needs any one, as one requirement per scope.
 * Extend for a project's own conventions.
 */
class Authentication
{
    /** @var list<string> middleware that needs every scope it lists */
    protected const ALL_SCOPES = ['scopes', 'Laravel\Passport\Http\Middleware\CheckScopes', 'Laravel\Passport\Http\Middleware\CheckToken'];

    /** @var list<string> middleware that needs any one scope it lists */
    protected const ANY_SCOPE = ['scope', 'Laravel\Passport\Http\Middleware\CheckForAnyScope', 'Laravel\Passport\Http\Middleware\CheckTokenForAnyScope'];

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
     * every guard maps to a scheme: naming only some would say the others are not accepted. The
     * route's scope middleware adds the scopes.
     *
     * @param list<string>    $guards
     * @param iterable<mixed> $middleware
     *
     * @return list<OA\Security\Requirement>|null
     */
    public function requirements(array $guards, iterable $middleware = []): ?array
    {
        $schemes = [];
        foreach ($guards as $guard) {
            $scheme = $this->schemes[$guard] ?? null;
            if ($scheme === null) {
                return null;
            }
            $schemes[$scheme] = true;
        }

        $requirements = [];
        foreach (array_keys($schemes) as $scheme) {
            foreach ($this->scopes($middleware) as $scopes) {
                $requirements[] = new OA\Security\Requirement(scheme: $scheme, scopes: $scopes ?: null);
            }
        }

        return $requirements ?: null;
    }

    /**
     * The scope sets that let a request through, one per requirement.
     *
     * @param iterable<mixed> $middleware
     *
     * @return list<list<string>>
     */
    protected function scopes(iterable $middleware): array
    {
        $all = [];
        $sets = [[]];
        foreach ($middleware as $entry) {
            if (!is_string($entry) || !str_contains($entry, ':')) {
                continue;
            }

            [$name, $parameters] = explode(':', $entry, 2);
            $scopes = array_map(trim(...), explode(',', $parameters));
            if (in_array($name, static::ALL_SCOPES, true)) {
                $all = [...$all, ...$scopes];
            } elseif (in_array($name, static::ANY_SCOPE, true)) {
                $sets = array_merge(...array_map(static fn (array $set): array => array_map(static fn (string $scope): array => [...$set, $scope], $scopes), $sets));
            }
        }

        return array_map(static fn (array $set): array => array_values(array_unique([...$all, ...$set])), $sets);
    }
}
