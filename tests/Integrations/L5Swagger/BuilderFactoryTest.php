<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Integrations\L5Swagger;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use OpenApi\Spec as OA;
use OpenApi\Specification;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Radebatz\OpenApi\Introspector\Integrations\L5Swagger\BuilderFactory;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\AccountController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\ClassicController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\FormRequestController;
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

    public function testABareAuthUsesTheApplicationsDefaultGuard(): void
    {
        Container::getInstance()->instance('config', new Repository(['auth' => ['defaults' => ['guard' => 'sanctum']]]));
        $this->router->middleware(['api', 'auth'])->get('account', [AccountController::class, 'show']);

        $document = $this->build($this->mapping(['sanctum' => 'bearerAuth', 'web' => 'cookieAuth']));

        $this->assertSame([['bearerAuth' => []]], $document['paths']['/account']['get']['security']);
    }

    public function testABareAuthUsesWebWithoutAConfiguredDefault(): void
    {
        $this->router->middleware(['api', 'auth'])->get('account', [AccountController::class, 'show']);

        $document = $this->build($this->mapping(['sanctum' => 'bearerAuth', 'web' => 'cookieAuth']));

        $this->assertSame([['cookieAuth' => []]], $document['paths']['/account']['get']['security']);
    }

    public function testASubclassGivesTheLogger(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $this->router->middleware('api')->post('users/name', [FormRequestController::class, 'rename']);

        $factory = new class ($logger) extends BuilderFactory {
            public function __construct(private readonly LoggerInterface $logger)
            {
            }

            protected function logger(): LoggerInterface
            {
                return $this->logger;
            }
        };
        $this->build($factory);

        $this->assertStringContainsString('UserBoundRequest', implode("\n", $logger->messages), 'the adapter reports to it');
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

    /**
     * @param array<string, string> $schemes
     */
    private function mapping(array $schemes): BuilderFactory
    {
        return new class ($schemes) extends BuilderFactory {
            /**
             * @param array<string, string> $schemes
             */
            public function __construct(private readonly array $schemes)
            {
            }

            protected function schemes(): array
            {
                return $this->schemes;
            }
        };
    }
}
