<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Integrations\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Registers what the package adds to a Laravel application: the `openapi:inventory` command and
 * its settings. Laravel finds it through package discovery.
 *
 * `php artisan vendor:publish --tag=openapi-introspector-config` copies the settings to
 * `config/openapi-introspector.php`.
 */
class IntrospectorServiceProvider extends ServiceProvider
{
    public const CONFIG = 'openapi-introspector';

    public function register(): void
    {
        $this->mergeConfigFrom(self::configFile(), self::CONFIG);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InventoryCommand::class]);
            $this->publishes([self::configFile() => config_path(self::CONFIG . '.php')], self::CONFIG . '-config');
        }
    }

    protected static function configFile(): string
    {
        return __DIR__ . '/config/' . self::CONFIG . '.php';
    }
}
