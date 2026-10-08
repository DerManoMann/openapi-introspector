<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests;

use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use OpenApi\Spec as OA;
use OpenApi\Specification;
use PHPUnit\Framework\TestCase;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Introspector;
use Radebatz\OpenApi\Introspector\Status;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\PrefixedController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\RoutedController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\UsersController;

/**
 * Attributes on a route's handler add to what the route gives: the route supplies existence,
 * method, path and path parameters, the attributes the rest, and the attribute wins on a field
 * both set.
 */
final class FoldTest extends TestCase
{
    public function testABareResponseJoinsTheRoutesOperation(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('users/{id}', [RoutedController::class, 'show'])->where('id', '[0-9]+');

        [$document, $introspector] = $this->build($router, RoutedController::class);

        $operation = $document['paths']['/users/{id}']['get'];
        $this->assertSame('The user', $operation['responses'][200]['description'], 'the response nests into the route operation');
        $this->assertSame('Show one user.', $operation['summary']);
        $this->assertCount(1, $operation['parameters']);
        $this->assertSame('[0-9]+', $operation['parameters'][0]['schema']['pattern'], 'the route still gives its parameter');
        $this->assertSame(Status::Matched, $introspector->inventory()->get('get', '/users/{id}')?->status);
    }

    public function testAParameterBothDescribeFoldsByName(): void
    {
        $router = new Router(new Dispatcher());
        $router->put('users/{id}', [RoutedController::class, 'update'])->where('id', '[0-9]+');

        [$document] = $this->build($router, RoutedController::class);

        $operation = $document['paths']['/users/{id}']['put'];
        $this->assertSame('Replace a user', $operation['summary'], "an operation without a path gets the route's");
        $this->assertCount(1, $operation['parameters'], 'one parameter, not one from each side');
        $parameter = $operation['parameters'][0];
        $this->assertSame('The user id', $parameter['description']);
        $this->assertSame('string', $parameter['schema']['type'], "the attribute's schema wins");
        $this->assertSame('[0-9]+', $parameter['schema']['pattern'], "the route's pattern survives where the attribute has none");
    }

    public function testAnOperationDeclaredOnAnotherMethodIsCompletedFromTheRoute(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('accounts/{id}', [UsersController::class, 'show'])->where('id', '[0-9]+');

        [$document, $introspector] = $this->build($router, RoutedController::class);

        $operation = $document['paths']['/accounts/{id}']['get'];
        $this->assertSame(['Accounts'], $operation['tags'], 'the attribute operation is the base');
        $this->assertSame('The account', $operation['responses'][200]['description']);
        $this->assertCount(1, $operation['parameters']);
        $this->assertSame('[0-9]+', $operation['parameters'][0]['schema']['pattern'], 'the route still gives its parameter');
        $this->assertSame(Status::Matched, $introspector->inventory()->get('get', '/accounts/{id}')?->status);
    }

    public function testAReferencedParameterIsNotAddedAgain(): void
    {
        $router = new Router(new Dispatcher());
        $router->delete('users/{id}', [RoutedController::class, 'destroy']);

        [$document] = $this->build($router, RoutedController::class);

        $operation = $document['paths']['/users/{id}']['delete'];
        $this->assertSame([['$ref' => '#/components/parameters/UserId']], $operation['parameters']);
    }

    public function testAHandlerServingSeveralRoutesFoldsOnlyTheOneItsAttributeNames(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('items/{page?}', [RoutedController::class, 'items']);

        [$document, $introspector] = $this->build($router, RoutedController::class);

        $this->assertSame('The first page', $document['paths']['/items']['get']['summary']);
        $this->assertArrayHasKey('/items/{page}', $document['paths'], 'the other route is contributed whole');
        $this->assertSame(Status::Matched, $introspector->inventory()->get('get', '/items')?->status);
        $this->assertSame(Status::Introspected, $introspector->inventory()->get('get', '/items/{page}')?->status);
    }

    public function testAControllerPrefixIsNotAppliedTwice(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('api/users/{id}', [PrefixedController::class, 'show']);
        $router->get('api/users', [PrefixedController::class, 'index']);

        [$document, $introspector] = $this->build($router, PrefixedController::class);

        $this->assertSame(['/api/users/{id}', '/api/users'], array_keys($document['paths']));
        $this->assertSame('All users', $document['paths']['/api/users']['get']['responses'][200]['description']);
        $this->assertSame(Status::Introspected, $introspector->inventory()->get('get', '/api/users/{id}')?->status);
    }

    public function testBuildingTwiceGivesTheSameDocument(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('api/users/{id}', [PrefixedController::class, 'show'])->where('id', '[0-9]+');
        $router->get('ping', static fn (): string => 'pong');

        $introspector = (new Introspector())->withAdapter(new LaravelAdapter($router));
        $first = $introspector->register($this->builder(PrefixedController::class))->build()->toArray();
        $second = $introspector->register($this->builder(PrefixedController::class))->build()->toArray();

        $this->assertSame($first, $second);
    }

    /**
     * @param class-string $source
     *
     * @return array{array<string, mixed>, Introspector}
     */
    private function build(Router $router, string $source): array
    {
        $introspector = (new Introspector())->withAdapter(new LaravelAdapter($router));
        $document = $introspector->register($this->builder($source))->build()->toArray();

        return [$document, $introspector];
    }

    /**
     * @param class-string $source
     */
    private function builder(string $source): Builder
    {
        return (new Builder())
            ->setMode(Mode::SPEC)
            ->addSource(new \ReflectionClass($source))
            ->withSpecification(static function (Specification $specification): void {
                $specification->add(new OA\Info(title: 'Folded', version: '1.0.0'));
            });
    }
}
