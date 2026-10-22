<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Adapters;

use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use OpenApi\Spec as OA;
use PHPUnit\Framework\TestCase;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\PingController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\PostsController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\TypedController;
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

    public function testAFilteredListOfRoutesYieldsOnlyThoseRoutes(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('api/users', [UsersController::class, 'index'])->middleware('api');
        $router->get('sanctum/csrf-cookie', static fn (): string => 'cookie');

        $routes = array_filter(
            $router->getRoutes()->getRoutes(),
            static fn (Route $route): bool => in_array('api', $route->middleware(), true),
        );

        $operations = $this->operations(new LaravelAdapter($routes));

        $this->assertCount(1, $operations);
        $this->assertSame('/api/users', $operations[0]->path);
    }

    public function testAPrefixComesOffThePathsThatStartWithIt(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('api/users', [UsersController::class, 'index']);
        $router->get('api', [UsersController::class, 'index']);
        $router->get('apis', [UsersController::class, 'index']);
        $router->get('dashboard', [UsersController::class, 'index']);

        $paths = array_map(
            static fn (OA\Operation $operation): ?string => $operation->path,
            $this->operations(new LaravelAdapter($router, prefix: '/api')),
        );

        $this->assertSame(['/users', '/', '/apis', '/dashboard'], $paths, 'whole segments only, and a path without it is left alone');
    }

    public function testAScalarSignatureTypeCarriesTheParameterReflector(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('number/{id}', [TypedController::class, 'number']);
        $router->get('bound/{user}', [TypedController::class, 'bound']);
        $router->get('untyped/{id}', [TypedController::class, 'untyped']);

        [$number, $bound, $untyped] = array_map(
            static fn (OA\Operation $operation): OA\Parameter => ($operation->parameters ?? [])[0],
            $this->operations(new LaravelAdapter($router)),
        );

        $this->assertInstanceOf(\ReflectionParameter::class, $number->getReflector());
        $this->assertInstanceOf(OA\Schema::class, $number->schema);
        $this->assertNull($number->schema->type, 'the type is read from the signature');
        $this->assertNull($bound->getReflector(), 'a class type is route-model binding, not the path value');
        $this->assertNull($untyped->getReflector());
        $this->assertInstanceOf(OA\Schema::class, $untyped->schema);
        $this->assertSame('string', $untyped->schema->type);
    }

    public function testAPlaceholderBoundToAModelGetsANotFound(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('posts/{post}', [PostsController::class, 'show']);
        $router->get('blog/{blog_post}', [PostsController::class, 'snake']);
        $router->get('maybe/{post}', [PostsController::class, 'defaulted']);
        $router->get('bound/{user}', [TypedController::class, 'bound']);
        $router->get('number/{id}', [TypedController::class, 'number']);

        [$model, $snake, $defaulted, $bound, $number] = array_map(
            static fn (OA\Operation $operation): ?array => $operation->responses,
            $this->operations(new LaravelAdapter($router)),
        );

        $this->assertNotNull($model);
        $this->assertCount(1, $model);
        $this->assertSame(404, $model[0]->response);
        $this->assertSame('Not Found', $model[0]->description);
        $this->assertNotNull($snake, 'a snake case placeholder binds the camel case parameter');
        $this->assertNull($defaulted, 'a default takes the place of a missing row');
        $this->assertNull($bound, 'a class that is not routable is not bound');
        $this->assertNull($number);
    }

    /**
     * @return list<OA\Operation>
     */
    private function operations(LaravelAdapter $adapter): array
    {
        return iterator_to_array($adapter->operations(), false);
    }
}
