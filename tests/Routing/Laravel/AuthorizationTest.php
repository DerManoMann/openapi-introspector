<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Routing\Laravel;

use Illuminate\Auth\Middleware\Authorize;
use PHPUnit\Framework\TestCase;
use Radebatz\OpenApi\Introspector\Routing\Laravel\Authorization;

final class AuthorizationTest extends TestCase
{
    public function testLaravelsAndSanctumsNamesAuthorize(): void
    {
        $authorization = new Authorization();

        $this->assertTrue($authorization->authorizes(['api', 'can:update,post']));
        $this->assertTrue($authorization->authorizes([Authorize::using('viewAny', 'App\Models\Post')]));
        $this->assertTrue($authorization->authorizes(['abilities:check-status,place-orders']));
        $this->assertTrue($authorization->authorizes(['ability:check-status']));
        $this->assertTrue($authorization->authorizes(['Laravel\Sanctum\Http\Middleware\CheckAbilities:check-status']), 'Sanctum by class, installed or not');
        $this->assertFalse($authorization->authorizes(['api', 'auth:sanctum', 'throttle:60,1', static fn (): null => null]));
    }

    public function testAProjectsOwnMiddlewareReplacesTheNames(): void
    {
        $authorization = new Authorization(names: ['role']);

        $this->assertTrue($authorization->authorizes(['role:admin']));
        $this->assertFalse($authorization->authorizes(['abilities:check-status']));
        $this->assertTrue($authorization->authorizes([Authorize::class . ':update']), 'the Authorize class always counts');
    }
}
