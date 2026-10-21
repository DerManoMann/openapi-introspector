<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Adapters;

use OpenApi\Spec as OA;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Radebatz\OpenApi\Introspector\Adapters\SlimAdapter;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\PingController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\TypedController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\UsersController;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteInterface;

final class SlimAdapterTest extends TestCase
{
    public function testHeadIsDroppedAsAnArtefactOfGet(): void
    {
        $app = AppFactory::create();
        $app->map(['GET', 'HEAD'], '/users', [UsersController::class, 'index']);

        $operations = $this->operations($app);

        $this->assertCount(1, $operations);
        $this->assertSame('get', $operations[0]->method);
    }

    public function testAConstraintInThePatternBecomesTheParameterPattern(): void
    {
        $app = AppFactory::create();
        $app->get('/users/{id:[0-9]+}/posts/{slug}', [UsersController::class, 'show']);

        $operation = $this->operations($app)[0];

        $this->assertSame('/users/{id}/posts/{slug}', $operation->path);
        $this->assertNotNull($operation->parameters);
        $this->assertSame('id', $operation->parameters[0]->name);
        $this->assertInstanceOf(OA\Schema::class, $id = $operation->parameters[0]->schema);
        $this->assertSame('integer', $id->type);
        $this->assertSame('[0-9]+', $id->pattern);
        $this->assertSame('slug', $operation->parameters[1]->name);
        $this->assertInstanceOf(OA\Schema::class, $slug = $operation->parameters[1]->schema);
        $this->assertNull($slug->pattern);
    }

    public function testAnOptionalSegmentYieldsOnePathPerCombination(): void
    {
        $app = AppFactory::create();
        $app->get('/users[/{id}]', [UsersController::class, 'show']);

        $operations = $this->operations($app);

        $this->assertSame(['/users', '/users/{id}'], array_map(static fn (OA\Operation $operation): ?string => $operation->path, $operations));
        $this->assertNull($operations[0]->parameters);
        $this->assertCount(1, $operations[1]->parameters ?? []);
    }

    public function testEveryCallableFormResolvesToItsMethod(): void
    {
        $app = AppFactory::create();
        $app->get('/array', [UsersController::class, 'index']);
        $app->get('/colon', UsersController::class . ':index');
        $app->get('/double-colon', UsersController::class . '::index');
        $app->get('/invokable', PingController::class);
        $app->get('/instance', new PingController());
        $app->get('/closure', static fn (): string => 'pong');

        $names = [];
        foreach ($this->operations($app) as $operation) {
            $reflector = $operation->getReflector();
            $names[$operation->path] = $reflector instanceof \ReflectionMethod ? $reflector->getName() : null;
        }

        $this->assertSame([
            '/array' => 'index',
            '/colon' => 'index',
            '/double-colon' => 'index',
            '/invokable' => '__invoke',
            '/instance' => '__invoke',
            '/closure' => null,
        ], $names);
    }

    public function testARouteNameBecomesTheOperationIdOnlyWhenAsked(): void
    {
        $app = AppFactory::create();
        $app->get('/users', [UsersController::class, 'index'])->setName('users.index');

        $this->assertNull($this->operations($app)[0]->operationId);
        $this->assertSame('users.index', $this->operations($app, nameAsOperationId: true)[0]->operationId);
    }

    public function testAFilteredListOfRoutesYieldsOnlyThoseRoutes(): void
    {
        $app = AppFactory::create();
        $app->get('/api/users', [UsersController::class, 'index']);
        $app->get('/health', PingController::class);

        $routes = array_filter(
            $app->getRouteCollector()->getRoutes(),
            static fn (RouteInterface $route): bool => str_starts_with($route->getPattern(), '/api'),
        );

        $operations = iterator_to_array((new SlimAdapter($routes))->operations(), false);

        $this->assertCount(1, $operations);
        $this->assertSame('/api/users', $operations[0]->path);
    }

    public function testAPrefixComesOffThePathsThatStartWithIt(): void
    {
        $app = AppFactory::create();
        $app->get('/api/users[/{id:[0-9]+}]', [UsersController::class, 'show']);
        $app->get('/dashboard', [UsersController::class, 'index']);

        $paths = array_map(static fn (OA\Operation $operation): ?string => $operation->path, $this->operations($app, prefix: 'api'));

        $this->assertSame(['/users', '/users/{id}', '/dashboard'], $paths);
    }

    public function testAScalarSignatureTypeCarriesTheParameterReflector(): void
    {
        $app = AppFactory::create();
        $app->get('/number/{id}', [TypedController::class, 'number']);

        $parameter = ($this->operations($app)[0]->parameters ?? [])[0];

        $this->assertInstanceOf(\ReflectionParameter::class, $parameter->getReflector());
        $this->assertInstanceOf(OA\Schema::class, $parameter->schema);
        $this->assertNull($parameter->schema->type);
    }

    /**
     * @param App<ContainerInterface|null> $app
     *
     * @return list<OA\Operation>
     */
    private function operations(App $app, bool $nameAsOperationId = false, string $prefix = ''): array
    {
        return iterator_to_array((new SlimAdapter($app->getRouteCollector(), $nameAsOperationId, prefix: $prefix))->operations(), false);
    }
}
