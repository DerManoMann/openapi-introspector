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
use Radebatz\OpenApi\Introspector\Tests\Fixtures\RenamedController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\RoutedController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\SharedParameterController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\UsersController;

/**
 * Attributes on a route's handler add to what the route gives: the route supplies existence,
 * method, path and path parameters, the attributes the rest, and the attribute wins on a field
 * both set, except a path parameter's `pattern`, which is what the router matches.
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

    public function testTheRoutesPatternWinsOverTheAttributes(): void
    {
        $router = new Router(new Dispatcher());
        $router->patch('users/{id}', [RoutedController::class, 'rename'])->where('id', '[0-9]+');

        [$document] = $this->build($router, RoutedController::class);

        $schema = $document['paths']['/users/{id}']['patch']['parameters'][0]['schema'];
        $this->assertSame('[0-9]+', $schema['pattern'], "the router matches the route's pattern, not the attribute's");
        $this->assertSame('integer', $schema['type'], "the attribute's type still wins");
    }

    public function testAnUnconstrainedRouteLeavesTheAttributesPattern(): void
    {
        $router = new Router(new Dispatcher());
        $router->patch('users/{id}', [RoutedController::class, 'rename']);

        [$document] = $this->build($router, RoutedController::class);

        $schema = $document['paths']['/users/{id}']['patch']['parameters'][0]['schema'];
        $this->assertSame('[1-9][0-9]*', $schema['pattern']);
        $this->assertSame('integer', $schema['type']);
    }

    public function testARenamedPlaceholderFoldsIntoTheAttributesParameter(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('events/{event}', [RenamedController::class, 'show'])->where('event', '[0-9]+');

        [$document, $introspector] = $this->build($router, RenamedController::class);

        $operation = $document['paths']['/events/{id}']['get'];
        $this->assertCount(1, $operation['parameters'], "no parameter under the route's name");
        $parameter = $operation['parameters'][0];
        $this->assertSame('id', $parameter['name']);
        $this->assertSame('The event id', $parameter['description']);
        $this->assertSame('[0-9]+', $parameter['schema']['pattern'], "the route's pattern, by position");
        $this->assertSame(Status::Matched, $introspector->inventory()->get('get', '/events/{event}')?->status);
    }

    public function testARenamedPlaceholderIsAddedUnderTheAttributesName(): void
    {
        $router = new Router(new Dispatcher());
        $router->delete('events/{event}', [RenamedController::class, 'destroy'])->where('event', '[0-9]+');

        [$document] = $this->build($router, RenamedController::class);

        $parameters = $document['paths']['/events/{id}']['delete']['parameters'];
        $this->assertSame(['id'], array_column($parameters, 'name'), 'the name the path gives it');
        $this->assertSame('[0-9]+', $parameters[0]['schema']['pattern']);
    }

    public function testAPlaceholderTheAttributesPathLacksIsNotAdded(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('reports/{year}/{id}', [RenamedController::class, 'report']);

        [$document] = $this->build($router, RenamedController::class);

        $parameters = $document['paths']['/reports/{id}']['get']['parameters'];
        $this->assertSame(['id'], array_column($parameters, 'name'), 'a path parameter the path does not contain would be invalid');
    }

    public function testAnOperationOnAnotherMethodIsFoundWhateverItsPlaceholdersAreCalled(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('accounts/{account}', [UsersController::class, 'show'])->where('account', '[0-9]+');

        [$document, $introspector] = $this->build($router, RoutedController::class);

        $this->assertArrayNotHasKey('/accounts/{account}', $document['paths'], 'the route is not added beside the attribute operation');
        $operation = $document['paths']['/accounts/{id}']['get'];
        $this->assertSame(['Accounts'], $operation['tags']);
        $this->assertSame(['id'], array_column($operation['parameters'], 'name'));
        $this->assertSame('[0-9]+', $operation['parameters'][0]['schema']['pattern']);
        $this->assertSame(Status::Matched, $introspector->inventory()->get('get', '/accounts/{account}')?->status);
    }

    public function testRoutesOfOneShapeToldApartByTheirConstraintsAreBothKept(): void
    {
        $router = new Router(new Dispatcher());
        $router->delete('orders/{order_id}', [UsersController::class, 'show'])->where('order_id', '[0-9]+');
        $router->delete('orders/{hash}', [UsersController::class, 'index'])->where('hash', '[a-z]+');

        [, $introspector] = $this->build($router, RenamedController::class);

        $this->assertSame(Status::Introspected, $introspector->inventory()->get('delete', '/orders/{order_id}')?->status);
        $this->assertSame(Status::Introspected, $introspector->inventory()->get('delete', '/orders/{hash}')?->status);
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

    public function testAParameterThePathItemDeclaresIsNotShadowed(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('things/{id}', [SharedParameterController::class, 'show'])->where('id', '[0-9]+');

        [$document] = $this->build($router, SharedParameterController::class);

        $pathItem = $document['paths']['/things/{id}'];
        $this->assertSame('The thing id', $pathItem['parameters'][0]['description']);
        $this->assertArrayNotHasKey('parameters', $pathItem['get'], "an operation-level `id` would replace the path item's whole");
    }

    public function testAContributedRouteLeavesThePathItemsParametersAlone(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('things/{id}/history/{page?}', [SharedParameterController::class, 'history'])->where('id', '[0-9]+');

        [$document] = $this->build($router, SharedParameterController::class);

        $this->assertArrayNotHasKey('parameters', $document['paths']['/things/{id}/history']['get'], 'a handler serving several routes contributes them whole');
        $parameters = $document['paths']['/things/{id}/history/{page}']['get']['parameters'];
        $this->assertSame(['page'], array_column($parameters, 'name'), 'what the path item declares is not repeated');
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

        $introspector = (new Introspector())->addAdapter(new LaravelAdapter($router));
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
        $introspector = (new Introspector())->addAdapter(new LaravelAdapter($router));
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
