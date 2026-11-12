<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

use OpenApi\Augmenter\Inheritance;
use OpenApi\Builder\Hooks;
use OpenApi\Contracts\PluginInterface;
use OpenApi\Resolver;
use OpenApi\Utils\Pipeline;
use OpenApi\Utils\TypedList;
use Radebatz\OpenApi\Introspector\Schemas\LaravelData\DataOperations;
use Radebatz\OpenApi\Introspector\Schemas\LaravelData\DataResolver;
use Spatie\LaravelData\Contracts\BaseData;

/**
 * Describes spatie/laravel-data objects: a referenced data class becomes a component, and an
 * operation gets the data objects its handler takes and returns.
 *
 * ```php
 * (new Builder())->withPlugins([LaravelDataPlugin::class]);
 * ```
 *
 * It works without `IntrospectorPlugin`, on controllers documented with attributes, and with it on
 * the routes that plugin reads as well. It takes no settings, and does nothing where
 * laravel-data is not installed. The package
 * declares it for swagger-php's plugin discovery (7.6 or later), so
 * `Builder::withDiscoveredPlugins()` finds it. Data classes are read through Laravel's container,
 * so the application has to be booted.
 */
class LaravelDataPlugin implements PluginInterface
{
    public function configure(Hooks $hooks, array $config): void
    {
        if ($config !== []) {
            throw new \InvalidArgumentException(sprintf('%s takes no settings, got "%s".', static::class, implode('", "', array_keys($config))));
        }

        if (!interface_exists(BaseData::class)) {
            return;
        }

        $hooks->withResolver(static function (Resolver $chain): void {
            $chain->withResolvers(static function (TypedList $resolvers): void {
                $resolvers->add(new DataResolver());
            });
        });
        // after IntrospectorPlugin's augmenter, which always goes first, and before types are read
        $hooks->withAugmenters(static fn (Pipeline $augmenters): Pipeline => $augmenters->insert(new DataOperations(), Inheritance::class));
    }
}
