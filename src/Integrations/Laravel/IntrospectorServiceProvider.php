<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Integrations\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Registers what the package adds to a Laravel application: the `openapi:inventory` command.
 * Laravel finds it through package discovery.
 */
class IntrospectorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InventoryCommand::class]);
        }
    }
}
