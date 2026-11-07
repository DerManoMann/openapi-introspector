<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use OpenApi\Spec as OA;
use OpenApi\Specification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Adapters\SlimAdapter;
use Radebatz\OpenApi\Introspector\Introspector;
use Radebatz\OpenApi\Introspector\IntrospectorPlugin;
use Radebatz\OpenApi\Introspector\Routing\Laravel\RouteSource;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\AccountController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\FormRequestController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\UsersController;

/**
 * The plugin given an adapter, and naming one in its settings, with the router read from the
 * container as in a running Laravel application.
 */
#[RequiresMethod(Builder::class, 'withPlugins')]
final class IntrospectorPluginTest extends TestCase
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

    public function testAnAdapterGivenAddsWhatRegisterAdds(): void
    {
        $this->router->get('users/{id}', [UsersController::class, 'show']);

        $expected = (new Introspector())->addAdapter(new LaravelAdapter($this->router))->register($this->builder())->build();
        $result = $this->builder()->withPlugins([new IntrospectorPlugin(new LaravelAdapter($this->router))])->build();

        $this->assertTrue($result->isValid());
        $this->assertEquals($expected->toArray(), $result->toArray());
    }

    public function testANamedAdapterDocumentsTheApiRoutesByDefault(): void
    {
        $this->router->middleware('api')->get('users', [UsersController::class, 'index']);
        $this->router->middleware('web')->get('home', [UsersController::class, 'index']);

        $document = $this->build([LaravelAdapter::class => []]);

        $this->assertSame(['/users'], array_keys($document['paths']));
    }

    public function testTheSettingsGoToTheNamedAdapter(): void
    {
        $this->router->middleware('web')->get('users', [UsersController::class, 'index'])->name('users.index');
        $this->router->middleware(['web', 'auth'])->get('account', [AccountController::class, 'show']);
        $this->router->middleware('api')->get('ping', [UsersController::class, 'index']);

        $document = $this->build([LaravelAdapter::class => [
            'middleware' => 'web',
            'schemes' => ['session' => 'cookieAuth'],
            'defaultGuard' => 'session',
            'nameAsOperationId' => true,
        ]]);

        $this->assertSame(['/users', '/account'], array_keys($document['paths']));
        $this->assertSame('users.index', $document['paths']['/users']['get']['operationId']);
        $this->assertSame([['cookieAuth' => []]], $document['paths']['/account']['get']['security']);
    }

    public function testABareAuthUsesTheApplicationsDefaultGuard(): void
    {
        Container::getInstance()->instance('config', new Repository(['auth' => ['defaults' => ['guard' => 'sanctum']]]));
        $this->router->middleware(['api', 'auth'])->get('account', [AccountController::class, 'show']);

        $document = $this->build([LaravelAdapter::class => ['schemes' => ['sanctum' => 'bearerAuth', 'web' => 'cookieAuth']]]);

        $this->assertSame([['bearerAuth' => []]], $document['paths']['/account']['get']['security']);
    }

    public function testTheRoutesAndTheDefaultGuardAreReadWhenTheDocumentIsBuilt(): void
    {
        $builder = $this->builder()->withPlugins([IntrospectorPlugin::class => [LaravelAdapter::class => ['schemes' => ['sanctum' => 'bearerAuth']]]]);

        Container::getInstance()->instance('config', new Repository(['auth' => ['defaults' => ['guard' => 'sanctum']]]));
        $this->router->middleware(['api', 'auth'])->get('account', [AccountController::class, 'show']);

        $document = $builder->build()->toArray();

        $this->assertSame([['bearerAuth' => []]], $document['paths']['/account']['get']['security']);
    }

    public function testTheMiddlewareSettingTakesAListOfGroups(): void
    {
        $this->router->middleware('api')->get('users', [UsersController::class, 'index']);
        $this->router->middleware('admin')->get('admin/users', [UsersController::class, 'index']);
        $this->router->middleware('web')->get('home', [UsersController::class, 'index']);

        $document = $this->build([LaravelAdapter::class => ['middleware' => ['api', 'admin']]]);

        $this->assertSame(['/users', '/admin/users'], array_keys($document['paths']));
    }

    public function testTheRouteSourceSettingNamesWhereTheRoutesComeFrom(): void
    {
        $source = new class () extends RouteSource {
            public function routes(array $middleware): array
            {
                return array_values(array_filter(
                    parent::routes($middleware),
                    static fn (Route $route): bool => str_starts_with($route->uri(), 'v2/'),
                ));
            }
        };
        $this->router->middleware('api')->get('v1/users', [UsersController::class, 'index']);
        $this->router->middleware('api')->get('v2/users', [UsersController::class, 'index']);

        $document = $this->build([LaravelAdapter::class => ['routeSource' => $source::class]]);

        $this->assertSame(['/v2/users'], array_keys($document['paths']));
    }

    public function testANamedAdapterGetsTheBuildersLogger(): void
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

        (new Builder($logger))
            ->setMode(Mode::SPEC)
            ->withSpecification(static function (Specification $specification): void {
                $specification->add(new OA\Info(title: 'Introspected', version: '1.0.0'));
            })
            ->withPlugins([IntrospectorPlugin::class => [LaravelAdapter::class => []]])
            ->build();

        $this->assertNotEmpty(array_filter($logger->messages, static fn (string $message): bool => str_contains($message, 'rules')));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'no adapter' => [[], 'has no adapter'];
        yield 'an adapter that cannot create itself' => [[SlimAdapter::class => []], 'Invalid adapter "' . SlimAdapter::class . '"'];
        yield 'a setting in place of an adapter' => [['middleware' => 'api'], 'Invalid adapter "middleware"'];
        yield 'settings that are not an array' => [[LaravelAdapter::class => 'api'], 'Invalid settings string for the adapter "' . LaravelAdapter::class . '"'];
        yield 'an unknown adapter setting' => [[LaravelAdapter::class => ['group' => 'api']], 'Unknown setting "group"'];
        yield 'middleware' => [[LaravelAdapter::class => ['middleware' => ['api', '']]], 'Invalid value array for the "middleware" setting'];
        yield 'no middleware' => [[LaravelAdapter::class => ['middleware' => []]], 'Invalid value array for the "middleware" setting'];
        yield 'schemes' => [[LaravelAdapter::class => ['schemes' => ['bearerAuth']]], 'Invalid value array for the "schemes" setting'];
        yield 'defaultGuard' => [[LaravelAdapter::class => ['defaultGuard' => '']], 'Invalid value "" for the "defaultGuard" setting'];
        yield 'routeSource' => [[LaravelAdapter::class => ['routeSource' => Router::class]], 'Invalid value "' . Router::class . '" for the "routeSource" setting'];
        yield 'a routeSource needing arguments' => [[LaravelAdapter::class => ['routeSource' => Fixtures\ContainerRouteSource::class]], 'Invalid value "' . Fixtures\ContainerRouteSource::class . '" for the "routeSource" setting'];
        yield 'nameAsOperationId' => [[LaravelAdapter::class => ['nameAsOperationId' => 'yes']], 'Invalid value "yes" for the "nameAsOperationId" setting'];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testThrowsForSettingsItCannotUse(array $settings, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new Builder())->withPlugins([IntrospectorPlugin::class => $settings]);
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function build(array $settings): array
    {
        $result = $this->builder()->withPlugins([IntrospectorPlugin::class => $settings])->build();
        $this->assertTrue($result->isValid());

        return $result->toArray();
    }

    private function builder(): Builder
    {
        return (new Builder())
            ->setMode(Mode::SPEC)
            ->withSpecification(static function (Specification $specification): void {
                $specification->add(new OA\Info(title: 'Introspected', version: '1.0.0'));
            });
    }
}
