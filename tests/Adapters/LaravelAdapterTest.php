<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Adapters;

use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use OpenApi\Spec as OA;
use PHPUnit\Framework\TestCase;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\PingController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\UsersController;

final class LaravelAdapterTest extends TestCase
{
    public function testHeadIsDroppedAsAnArtefactOfGet(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('users', [UsersController::class, 'index']);

        $operations = $this->operations(new LaravelAdapter($router));

        $this->assertCount(1, $operations);
        $this->assertSame('get', $operations[0]->method);
        $this->assertSame('/users', $operations[0]->path);
    }

    public function testAFallbackRouteIsNotAnEndpoint(): void
    {
        $router = new Router(new Dispatcher());
        $router->fallback(static fn (): string => 'nope');

        $this->assertSame([], $this->operations(new LaravelAdapter($router)));
    }

    public function testAControllerActionCarriesItsReflector(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('users/{id}', [UsersController::class, 'show']);

        $reflector = $this->operations(new LaravelAdapter($router))[0]->getReflector();

        $this->assertInstanceOf(\ReflectionMethod::class, $reflector);
        $this->assertSame('show', $reflector->getName());
    }

    public function testAnInvokableControllerCarriesItsInvoke(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('ping', PingController::class);

        $reflector = $this->operations(new LaravelAdapter($router))[0]->getReflector();

        $this->assertInstanceOf(\ReflectionMethod::class, $reflector);
        $this->assertSame(PingController::class, $reflector->getDeclaringClass()->getName());
        $this->assertSame('__invoke', $reflector->getName());
    }

    public function testAClosureRouteCarriesNoReflector(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('ping', static fn (): string => 'pong');

        $this->assertNull($this->operations(new LaravelAdapter($router))[0]->getReflector());
    }

    public function testAWhereBecomesTheParameterPattern(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('users/{id}/posts/{slug}', [UsersController::class, 'show'])->where('id', '[0-9]+');

        $parameters = $this->operations(new LaravelAdapter($router))[0]->parameters;

        $this->assertNotNull($parameters);
        $this->assertCount(2, $parameters);
        $this->assertSame('id', $parameters[0]->name);
        $this->assertInstanceOf(OA\Schema::class, $id = $parameters[0]->schema);
        $this->assertSame('integer', $id->type);
        $this->assertSame('[0-9]+', $id->pattern);
        $this->assertSame('slug', $parameters[1]->name);
        $this->assertInstanceOf(OA\Schema::class, $slug = $parameters[1]->schema);
        $this->assertSame('string', $slug->type);
        $this->assertNull($slug->pattern);
    }

    public function testAnOptionalParameterYieldsOnePathPerCombination(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('users/{id?}', [UsersController::class, 'show']);

        $operations = $this->operations(new LaravelAdapter($router));

        $this->assertSame(['/users/{id}', '/users'], array_map(static fn (OA\Operation $operation): ?string => $operation->path, $operations));
        $this->assertCount(1, $operations[0]->parameters ?? []);
        $this->assertNull($operations[1]->parameters);
    }

    public function testARouteNameBecomesTheOperationIdOnlyWhenAsked(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('users', [UsersController::class, 'index'])->name('users.index');

        $this->assertNull($this->operations(new LaravelAdapter($router))[0]->operationId);
        $this->assertSame('users.index', $this->operations(new LaravelAdapter($router, nameAsOperationId: true))[0]->operationId);
    }

    /**
     * @return list<OA\Operation>
     */
    private function operations(LaravelAdapter $adapter): array
    {
        return iterator_to_array($adapter->operations(), false);
    }
}
