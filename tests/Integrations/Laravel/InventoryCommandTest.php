<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Integrations\Laravel;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use OpenApi\Analysers\AnnotationFactoryInterface;
use OpenApi\Analysers\ReflectionAnalyser;
use OpenApi\Builder;
use OpenApi\Context;
use OpenApi\GeneratorAwareTrait;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RequiresMethod;
use Radebatz\OpenApi\Introspector\Integrations\L5Swagger\BuilderFactory;
use Radebatz\OpenApi\Introspector\Integrations\Laravel\IntrospectorServiceProvider;
use Radebatz\OpenApi\Introspector\Integrations\Laravel\InventoryCommand;
use Radebatz\OpenApi\Introspector\RoutesPlugin;
use Radebatz\OpenApi\Introspector\Routing\Laravel\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\Inventory\OrdersController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\InventoryClassic\AccountsController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\UsersController;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command in an application that documents with L5-Swagger: one routed operation, one
 * documented with no route, and one route nothing documents.
 */
final class InventoryCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [IntrospectorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('l5-swagger', [
            'default' => 'default',
            'documentations' => [
                'default' => [
                    'paths' => ['annotations' => [__DIR__ . '/../../Fixtures/Inventory']],
                ],
            ],
            'defaults' => [
                'paths' => ['excludes' => []],
                'scanOptions' => ['mode' => 'spec'],
                'constants' => ['INVENTORY_TITLE' => 'Orders'],
            ],
        ]);
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('api')->get('api/orders', [OrdersController::class, 'index']);
        Route::middleware('api')->get('api/users', [UsersController::class, 'index']);
        Route::middleware('web')->get('api/legacy', [UsersController::class, 'index']);
    }

    public function testListsWhatNeedsAttentionAndCountsTheRest(): void
    {
        $this->inventory()
            ->expectsTable(['Method', 'Path', 'Status', 'Adapter'], [['GET', '/api/orders/archive', 'unrouted', '']])
            ->expectsOutput('matched: 1, introspected: 1, unrouted: 1, conflict: 0')
            ->assertSuccessful();
    }

    public function testListsEveryEntry(): void
    {
        $this->inventory(['--all' => true])
            ->expectsTable(['Method', 'Path', 'Status', 'Adapter'], [
                ['GET', '/api/orders', 'matched', 'LaravelAdapter'],
                ['GET', '/api/users', 'introspected', 'LaravelAdapter'],
                ['GET', '/api/orders/archive', 'unrouted', ''],
            ])
            ->assertSuccessful();
    }

    public function testFailsOnAStatus(): void
    {
        $this->inventory(['--fail-on' => ['unrouted']])
            ->expectsOutput('1 entry fails the check')
            ->assertFailed();

        $this->inventory(['--fail-on' => ['conflict']])
            ->assertSuccessful();
    }

    public function testPrintsTheEntriesAsJson(): void
    {
        $this->inventory(['--format' => 'json', '--status' => ['unrouted']])
            ->expectsOutput((string) json_encode([
                ['method' => 'GET', 'path' => '/api/orders/archive', 'status' => 'unrouted', 'adapter' => null],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();
    }

    #[RequiresMethod(Builder::class, 'withPlugins')]
    public function testFollowsTheAdapterSettingsOfTheRoutesPlugin(): void
    {
        $this->app['config']->set('l5-swagger.defaults.scanOptions.plugins', [
            RoutesPlugin::class => [LaravelAdapter::class => ['middleware' => 'web']],
        ]);

        $this->inventory(['--all' => true])
            ->expectsTable(['Method', 'Path', 'Status', 'Adapter'], [
                ['GET', '/api/legacy', 'introspected', 'LaravelAdapter'],
                ['GET', '/api/orders', 'unrouted', ''],
                ['GET', '/api/orders/archive', 'unrouted', ''],
            ])
            ->assertSuccessful();
    }

    public function testKeepsTheJsonOutputParseable(): void
    {
        $command = $this->app->make(InventoryCommand::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $tester->execute(['--format' => 'json', '--fail-on' => ['unrouted']], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame([['method' => 'GET', 'path' => '/api/orders', 'status' => 'matched', 'adapter' => LaravelAdapter::class], ['method' => 'GET', 'path' => '/api/users', 'status' => 'introspected', 'adapter' => LaravelAdapter::class], ['method' => 'GET', 'path' => '/api/orders/archive', 'status' => 'unrouted', 'adapter' => null]], json_decode($tester->getDisplay(), true));
        $this->assertStringContainsString('1 entry fails the check', $tester->getErrorOutput());
    }

    public function testFailsWhenTheBuildSkipsAClass(): void
    {
        if (!InstalledVersions::satisfies(new VersionParser(), 'zircote/swagger-php', '>=7.5')) {
            $this->markTestSkipped('Before swagger-php 7.5 a class that cannot be loaded is a warning.');
        }

        $this->app['config']->set('l5-swagger.documentations.default.paths.annotations', [__DIR__ . '/../../Fixtures/InventoryBroken']);

        $this->inventory(['--fail-on' => ['unrouted']])
            ->expectsOutput('The build logged 1 error, so the inventory may be incomplete.')
            ->assertExitCode(2);
    }

    #[RequiresMethod(Builder::class, 'withPlugins')]
    public function testRejectsInvalidAdapterSettings(): void
    {
        $this->app['config']->set('l5-swagger.defaults.scanOptions.plugins', [
            RoutesPlugin::class => [LaravelAdapter::class => ['middlewares' => 'api']],
        ]);

        $this->inventory()
            ->expectsOutputToContain('Unknown setting "middlewares"')
            ->assertExitCode(2);
    }

    public function testNotesWhenL5SwaggerDoesNotAddTheRoutes(): void
    {
        $this->inventory()
            ->expectsOutputToContain('does not add the routes')
            ->assertSuccessful();

        $this->app['config']->set('l5-swagger.defaults.scanOptions.builder_factory', BuilderFactory::class);

        $this->inventory()
            ->doesntExpectOutputToContain('does not add the routes')
            ->assertSuccessful();
    }

    public function testReadsAttributesWithTheAnalyserOfL5Swagger(): void
    {
        Route::middleware('api')->get('api/accounts', [AccountsController::class, 'index']);
        $this->app['config']->set('l5-swagger.documentations.default.paths.annotations', [__DIR__ . '/../../Fixtures/InventoryClassic']);
        $this->app['config']->set('l5-swagger.defaults.scanOptions.mode', 'hybrid');

        $this->inventory(['--status' => ['matched']])
            ->expectsTable(['Method', 'Path', 'Status', 'Adapter'], [['GET', '/api/accounts', 'matched', 'LaravelAdapter']])
            ->assertSuccessful();

        // an analyser that reads nothing leaves the route undescribed
        $this->app['config']->set('l5-swagger.defaults.scanOptions.analyser', new ReflectionAnalyser([new class () implements AnnotationFactoryInterface {
            use GeneratorAwareTrait;

            public function isSupported(): bool
            {
                return true;
            }

            public function build(\Reflector $reflector, Context $context): array
            {
                return [];
            }
        }]));

        $this->inventory(['--status' => ['matched']])
            ->expectsOutputToContain('matched: 0')
            ->assertSuccessful();
    }

    public function testRejectsAnUnknownStatus(): void
    {
        $this->inventory(['--fail-on' => ['missing']])
            ->expectsOutput('Invalid status "missing" for --fail-on. Expected one of matched, introspected, unrouted, conflict.')
            ->assertExitCode(2);
    }

    public function testRejectsAnUnknownDocumentation(): void
    {
        $this->inventory(['--documentation' => 'v2'])
            ->expectsOutput('Documentation config not found')
            ->assertExitCode(2);
    }

    public function testWithoutL5SwaggerReadsTheApplication(): void
    {
        $this->app['config']->set('l5-swagger', null);

        $this->inventory()
            ->expectsOutput('matched: 0, introspected: 2, unrouted: 0, conflict: 0')
            ->assertSuccessful();

        $this->inventory(['--documentation' => 'default'])
            ->expectsOutput('--documentation needs L5-Swagger and its configuration.')
            ->assertExitCode(2);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function inventory(array $parameters = []): PendingCommand
    {
        $command = $this->artisan('openapi:inventory', $parameters);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
