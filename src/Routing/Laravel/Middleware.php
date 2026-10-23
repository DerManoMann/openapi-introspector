<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Routing\Laravel;

use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * The status codes a route's middleware can answer with, before the handler runs.
 *
 * A middleware is named by its alias, as a route lists it (`can:update,post`), or by its class.
 * Laravel's own classes match their subclasses too. Classes of packages that are not a
 * dependency, such as Sanctum's, Passport's or spatie/laravel-permission's, match by name. Whether
 * the middleware answers for a given request is not known here: the status is a response the
 * route can give.
 *
 * A project with middleware of its own extends `DEFAULTS`, which a given map replaces:
 * `new Middleware([...Middleware::DEFAULTS, 'tenant' => Response::HTTP_NOT_FOUND])`.
 */
class Middleware
{
    /** @var array<string, int> */
    public const DEFAULTS = [
        'can' => Response::HTTP_FORBIDDEN,
        Authorize::class => Response::HTTP_FORBIDDEN,
        'verified' => Response::HTTP_FORBIDDEN,
        EnsureEmailIsVerified::class => Response::HTTP_FORBIDDEN,
        'signed' => Response::HTTP_FORBIDDEN,
        ValidateSignature::class => Response::HTTP_FORBIDDEN,
        'password.confirm' => Response::HTTP_LOCKED,
        RequirePassword::class => Response::HTTP_LOCKED,
        'throttle' => Response::HTTP_TOO_MANY_REQUESTS,
        ThrottleRequests::class => Response::HTTP_TOO_MANY_REQUESTS,
        // Sanctum
        'abilities' => Response::HTTP_FORBIDDEN,
        'ability' => Response::HTTP_FORBIDDEN,
        'Laravel\Sanctum\Http\Middleware\CheckAbilities' => Response::HTTP_FORBIDDEN,
        'Laravel\Sanctum\Http\Middleware\CheckForAnyAbility' => Response::HTTP_FORBIDDEN,
        // Passport, with the class names before and since 13.0
        'scopes' => Response::HTTP_FORBIDDEN,
        'scope' => Response::HTTP_FORBIDDEN,
        'Laravel\Passport\Http\Middleware\CheckScopes' => Response::HTTP_FORBIDDEN,
        'Laravel\Passport\Http\Middleware\CheckForAnyScope' => Response::HTTP_FORBIDDEN,
        'Laravel\Passport\Http\Middleware\CheckToken' => Response::HTTP_FORBIDDEN,
        'Laravel\Passport\Http\Middleware\CheckTokenForAnyScope' => Response::HTTP_FORBIDDEN,
        // spatie/laravel-permission
        'role' => Response::HTTP_FORBIDDEN,
        'permission' => Response::HTTP_FORBIDDEN,
        'role_or_permission' => Response::HTTP_FORBIDDEN,
        'Spatie\Permission\Middleware\RoleMiddleware' => Response::HTTP_FORBIDDEN,
        'Spatie\Permission\Middleware\PermissionMiddleware' => Response::HTTP_FORBIDDEN,
        'Spatie\Permission\Middleware\RoleOrPermissionMiddleware' => Response::HTTP_FORBIDDEN,
    ];

    /**
     * @param array<string, int> $statuses middleware name or class → status code
     */
    public function __construct(
        private readonly array $statuses = self::DEFAULTS,
    ) {
    }

    /**
     * @param iterable<mixed> $middleware
     *
     * @return list<int> each status once, in ascending order
     */
    public function statuses(iterable $middleware): array
    {
        $statuses = [];
        foreach ($middleware as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $name = explode(':', $entry, 2)[0];
            if (isset($this->statuses[$name])) {
                $statuses[] = $this->statuses[$name];
                continue;
            }

            foreach ($this->statuses as $class => $status) {
                if (class_exists($class) && is_a($name, $class, true)) {
                    $statuses[] = $status;
                    break;
                }
            }
        }

        $statuses = array_values(array_unique($statuses));
        sort($statuses);

        return $statuses;
    }
}
