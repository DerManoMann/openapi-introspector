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
use Radebatz\OpenApi\Introspector\Tests\Fixtures\ClassicController;

/**
 * Hybrid mode assembles classic attributes after the translator has run, so the translator
 * cannot see them; they still describe the route they sit on.
 */
final class HybridTest extends TestCase
{
    public function testClassicAttributesDescribeTheirRoute(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('users/{id}', [ClassicController::class, 'show'])->where('id', '[0-9]+');

        [$document, $introspector] = $this->build($router);

        $operation = $document['paths']['/users/{id}']['get'];
        $this->assertSame('Show a user', $operation['summary'], 'the classic operation is kept');
        $this->assertSame('The user', $operation['responses'][200]['description']);
        $this->assertCount(1, $operation['parameters']);
        $this->assertSame('[0-9]+', $operation['parameters'][0]['schema']['pattern'], 'the route still gives its parameter');
        $this->assertSame(Status::Matched, $introspector->inventory()->get('get', '/users/{id}')?->status);
    }

    public function testAnUndocumentedHandlerIsStillIntrospected(): void
    {
        $router = new Router(new Dispatcher());
        $router->get('ping', [ClassicController::class, 'ping']);

        [$document, $introspector] = $this->build($router);

        $this->assertSame('Ping the service.', $document['paths']['/ping']['get']['summary']);
        $this->assertSame(Status::Introspected, $introspector->inventory()->get('get', '/ping')?->status);
    }

    /**
     * @return array{array<string, mixed>, Introspector}
     */
    private function build(Router $router): array
    {
        $introspector = (new Introspector())->addAdapter(new LaravelAdapter($router));
        $builder = (new Builder())
            ->setMode(Mode::HYBRID)
            ->addSource(__DIR__ . '/Fixtures/ClassicController.php')
            ->withSpecification(static function (Specification $specification): void {
                $specification->add(new OA\Info(title: 'Hybrid', version: '1.0.0'));
            });
        $document = $introspector->register($builder)->build()->toArray();

        return [$document, $introspector];
    }
}
