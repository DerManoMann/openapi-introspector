<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Adapters;

use OpenApi\Spec as OA;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Radebatz\OpenApi\Introspector\Adapters\SlimAdapter;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\PingController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\UsersController;
use Slim\App;
use Slim\Factory\AppFactory;

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

    /**
     * @param App<ContainerInterface|null> $app
     *
     * @return list<OA\Operation>
     */
    private function operations(App $app, bool $nameAsOperationId = false): array
    {
        return iterator_to_array((new SlimAdapter($app->getRouteCollector(), $nameAsOperationId))->operations(), false);
    }
}
