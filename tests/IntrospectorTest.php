<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests;

use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use OpenApi\Spec as OA;
use OpenApi\Specification;
use PHPUnit\Framework\TestCase;
use Radebatz\OpenApi\Introspector\AdapterInterface;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Introspector;
use Radebatz\OpenApi\Introspector\Inventory\Entry;
use Radebatz\OpenApi\Introspector\Status;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\AttributedController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\UsersController;

/**
 * The introspector inside a real swagger-php build: what an adapter yields reaches the
 * document through the pipeline, attributes win where they already describe a route, and
 * the inventory says which was which.
 */
final class IntrospectorTest extends TestCase
{
    public function testARoutedOperationIsDerivedFromItsHandler(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('users/{id}', [UsersController::class, 'show'])->where('id', '[0-9]+');

        $introspector = (new Introspector())->withAdapter(new LaravelAdapter($router));
        $document = $introspector->register($this->builder())->build()->toArray();

        $operation = $document['paths']['/users/{id}']['get'];
        $this->assertSame('Show one user.', $operation['summary'], 'the summary is read from the handler docblock');
        $this->assertSame('id', $operation['parameters'][0]['name']);
        $this->assertSame('path', $operation['parameters'][0]['in']);
        $this->assertSame('integer', $operation['parameters'][0]['schema']['type'], 'a digits-only constraint is an integer');
        $this->assertSame('[0-9]+', $operation['parameters'][0]['schema']['pattern']);

        $entry = $introspector->inventory()->get('get', '/users/{id}');
        $this->assertInstanceOf(Entry::class, $entry);
        $this->assertSame(Status::Introspected, $entry->status);
        $this->assertSame(LaravelAdapter::class, $entry->adapter);
    }

    public function testAttributesWinWhereTheyDescribeTheRoute(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('users', [UsersController::class, 'index']);

        $introspector = (new Introspector())->withAdapter(new LaravelAdapter($router));
        $document = $introspector->register($this->builder()->addSource(new \ReflectionClass(AttributedController::class)))
            ->build()
            ->toArray();

        $operation = $document['paths']['/users']['get'];
        $this->assertSame('All the users', $operation['summary'], 'the attribute is kept whole');
        $this->assertSame('All users', $operation['responses'][200]['description']);

        $entry = $introspector->inventory()->get('get', '/users');
        $this->assertInstanceOf(Entry::class, $entry);
        $this->assertSame(Status::Matched, $entry->status);
        $this->assertSame(LaravelAdapter::class, $entry->adapter, 'the entry says which adapter serves the route');
    }

    public function testADescribedOperationNoRouteServesIsUnrouted(): void
    {
        $introspector = (new Introspector())->withAdapter(new LaravelAdapter(new Router(new Dispatcher())));
        $document = $introspector->register($this->builder()->addSource(new \ReflectionClass(AttributedController::class)))
            ->build()
            ->toArray();

        $this->assertArrayHasKey('/legacy', $document['paths'], 'the document is not the place to drop it');

        $entry = $introspector->inventory()->get('get', '/legacy');
        $this->assertInstanceOf(Entry::class, $entry);
        $this->assertSame(Status::Unrouted, $entry->status);
        $this->assertNull($entry->adapter);
        $this->assertCount(2, $introspector->inventory()->entries(Status::Unrouted));
    }

    public function testAClosureRouteIsContributedBare(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('ping', static fn (): string => 'pong');

        $document = (new Introspector())->withAdapter(new LaravelAdapter($router))
            ->register($this->builder())
            ->build()
            ->toArray();

        $operation = $document['paths']['/ping']['get'];
        $this->assertArrayNotHasKey('summary', $operation);
        $this->assertArrayHasKey('operationId', $operation, 'with no reflector the id is derived from method and path');
    }

    public function testTheFirstAdapterToYieldAKeyWins(): void
    {
        $introspector = (new Introspector())->withAdapter(
            $this->adapter(new OA\Operation\Get(path: '/things', operationId: 'first')),
            $this->adapter(new OA\Operation\Get(path: '/things', operationId: 'second')),
        );

        $specification = new Specification();
        $inventory = $introspector->introspect($specification);

        $this->assertCount(1, $specification->operations);
        $this->assertSame('first', $specification->operations[0]->operationId);
        $this->assertCount(1, $inventory->entries());
    }

    public function testAnOperationWithoutAPathIsADefectInTheAdapter(): void
    {
        $introspector = (new Introspector())->withAdapter($this->adapter(new OA\Operation(method: 'get')));

        $this->expectException(\InvalidArgumentException::class);
        $introspector->introspect(new Specification());
    }

    public function testTheInventoryExistsOnlyAfterIntrospection(): void
    {
        $this->expectException(\LogicException::class);
        (new Introspector())->inventory();
    }

    private function builder(): Builder
    {
        return (new Builder())
            ->setMode(Mode::SPEC)
            ->withSpecification(static function (Specification $specification): void {
                $specification->add(new OA\Info(title: 'Introspected', version: '1.0.0'));
            });
    }

    private function adapter(OA\Operation ...$operations): AdapterInterface
    {
        return new class (array_values($operations)) implements AdapterInterface {
            /**
             * @param list<OA\Operation> $operations
             */
            public function __construct(private readonly array $operations)
            {
            }

            public function operations(): iterable
            {
                return $this->operations;
            }
        };
    }
}
