<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Integrations\L5Swagger;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use OpenApi\Spec as OA;
use OpenApi\Specification;
use PHPUnit\Framework\TestCase;
use Radebatz\OpenApi\Introspector\Integrations\L5Swagger\BuilderFactory;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\ClassicController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\UsersController;

/**
 * The factory as L5-Swagger uses it: created from its class name, with the router from the
 * container, and given a source and an `Info` on the builder it returns.
 */
final class BuilderFactoryTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $container = new Container();
        Container::setInstance($container);
        $this->router = new Router(new Dispatcher($container), $container);
        $container->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        Container::setInstance();
    }

    public function testTheApiRoutesAreDocumented(): void
    {
        $this->router->middleware('api')->get('users', [UsersController::class, 'index']);
        $this->router->middleware('web')->get('home', [UsersController::class, 'index']);
        $this->router->get('ping', [UsersController::class, 'index']);

        $document = $this->build(new BuilderFactory());

        $this->assertSame(['/users'], array_keys($document['paths']));
    }

    public function testAttributesAreReadInHybridMode(): void
    {
        $this->router->middleware('api')->get('users/{id}', [ClassicController::class, 'show']);

        $document = $this->build(new BuilderFactory(), __DIR__ . '/../../Fixtures/ClassicController.php');

        $this->assertSame('Show a user', $document['paths']['/users/{id}']['get']['summary'], 'a classic attribute is read');
    }

    public function testASubclassChoosesTheRoutes(): void
    {
        $this->router->middleware('web')->get('home', [UsersController::class, 'index']);

        $factory = new class () extends BuilderFactory {
            protected function routes(): iterable
            {
                return Container::getInstance()->make(Router::class)->getRoutes()->getRoutes();
            }
        };

        $this->assertSame(['/home'], array_keys($this->build($factory)['paths']));
    }

    public function testASubclassChoosesTheMode(): void
    {
        $this->router->middleware('api')->get('users/{id}', [ClassicController::class, 'show']);

        $factory = new class () extends BuilderFactory {
            protected function mode(): Mode
            {
                return Mode::SPEC;
            }
        };

        $document = $this->build($factory, __DIR__ . '/../../Fixtures/ClassicController.php');

        $this->assertArrayNotHasKey('summary', $document['paths']['/users/{id}']['get'], 'spec mode does not read a classic attribute');
    }

    public function testASubclassConfiguresTheBuilder(): void
    {
        $factory = new class () extends BuilderFactory {
            protected function builder(): Builder
            {
                return parent::builder()->withSpecification(static function (Specification $specification): void {
                    $specification->add(new OA\Server(url: 'https://api.example.com'));
                });
            }
        };

        $this->assertSame('https://api.example.com', $this->build($factory)['servers'][0]['url']);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(BuilderFactory $factory, ?string $source = null): array
    {
        $builder = $factory->create()->withSpecification(static function (Specification $specification): void {
            $specification->add(new OA\Info(title: 'L5', version: '1.0.0'));
        });

        return ($source !== null ? $builder->addSource($source) : $builder)->build()->toArray();
    }
}
