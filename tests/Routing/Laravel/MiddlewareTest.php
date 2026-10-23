<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Routing\Laravel;

use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use PHPUnit\Framework\TestCase;
use Radebatz\OpenApi\Introspector\Routing\Laravel\Middleware;

final class MiddlewareTest extends TestCase
{
    public function testTheDefaultsByAliasAndClass(): void
    {
        $middleware = new Middleware();

        $this->assertSame([403], $middleware->statuses(['api', 'can:update,post']));
        $this->assertSame([403], $middleware->statuses([Authorize::using('viewAny', 'App\Models\Post')]));
        $this->assertSame([403], $middleware->statuses(['abilities:check-status', 'role:admin', 'scopes:read']), 'Sanctum, spatie and Passport');
        $this->assertSame([403], $middleware->statuses(['Laravel\Passport\Http\Middleware\CheckToken:read']), 'a package class by name, installed or not');
        $this->assertSame([403, 423, 429], $middleware->statuses(['throttle:60,1', 'password.confirm', 'verified', 'signed']), 'each status once, in order');
        $this->assertSame([429], $middleware->statuses([ThrottleRequestsWithRedis::class . ':api']), "a subclass of Laravel's own");
        $this->assertSame([], $middleware->statuses(['api', 'auth:sanctum', static fn (): null => null]));
    }

    public function testAProjectExtendsTheDefaults(): void
    {
        $middleware = new Middleware([...Middleware::DEFAULTS, 'tenant' => 404]);

        $this->assertSame([403, 404], $middleware->statuses(['tenant', 'can:view']));
        $this->assertSame([], (new Middleware(['tenant' => 404]))->statuses(['can:view']), 'a map replaces the defaults');
    }
}
