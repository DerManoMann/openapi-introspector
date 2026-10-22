<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Routing\Laravel;

use Illuminate\Auth\Middleware\Authenticate;
use OpenApi\Spec as OA;
use PHPUnit\Framework\TestCase;
use Radebatz\OpenApi\Introspector\Routing\Laravel\Authentication;

final class AuthenticationTest extends TestCase
{
    public function testLaravelsNamesAuthenticate(): void
    {
        $authentication = new Authentication();

        $this->assertSame(['web'], $authentication->guards(['api', 'auth']), "a bare auth is Laravel's default guard");
        $this->assertSame(['sanctum', 'api'], $authentication->guards(['auth:sanctum,api']));
        $this->assertSame(['sanctum'], $authentication->guards([Authenticate::class . ':sanctum']));
        $this->assertSame(['sanctum'], (new Authentication(default: 'sanctum'))->guards([Authenticate::class]));
        $this->assertNull($authentication->guards(['api', 'throttle:60,1', static fn (): null => null]));
    }

    public function testAProjectsOwnMiddlewareIsAdded(): void
    {
        $authentication = new Authentication(names: ['auth', 'jwt']);

        $this->assertSame(['admin'], $authentication->guards(['jwt:admin']));
    }

    public function testASchemeOnlyWhereEveryGuardMapsToOne(): void
    {
        $authentication = new Authentication(schemes: ['sanctum' => 'bearerAuth', 'api' => 'apiKey'], default: 'sanctum');
        $schemes = static fn (?array $requirements): ?array => $requirements === null ? null : array_map(static fn (OA\Security\Requirement $requirement): ?string => $requirement->scheme, $requirements);

        $this->assertSame(['bearerAuth', 'apiKey'], $schemes($authentication->requirements(['sanctum', 'api'])), 'any guard will do, so one requirement each');
        $this->assertSame(['bearerAuth'], $schemes($authentication->requirements($authentication->guards(['auth']) ?? [])), 'a bare auth uses the default guard');
        $this->assertNull($authentication->requirements(['sanctum', 'web']), 'naming only some would say the others are not accepted');
        $this->assertNull((new Authentication())->requirements(['web']), 'no map, no scheme');
    }
}
