<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

use OpenApi\Builder\Hooks;
use OpenApi\Contracts\PluginInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

/**
 * Adds the routes of one or more adapters to the document, as a swagger-php plugin.
 *
 * Given as an instance, it takes the adapters ready made, for any framework and setup:
 *
 * ```php
 * $builder->withPlugins([new IntrospectorPlugin(new SlimAdapter($app->getRouteCollector()))]);
 * ```
 *
 * Named by its class, its settings key each adapter that creates itself by class name, with that
 * adapter's own settings, so configuration can hold the whole setup. An empty array takes the
 * adapter's defaults:
 *
 * ```php
 * $builder->withPlugins([IntrospectorPlugin::class => [LaravelAdapter::class => ['middleware' => 'api']]]);
 * ```
 *
 * Plugins need swagger-php 7.3 or later.
 */
class IntrospectorPlugin implements PluginInterface, LoggerAwareInterface
{
    /** @var list<AdapterInterface> */
    protected array $adapters;

    protected ?LoggerInterface $logger = null;

    public function __construct(AdapterInterface ...$adapters)
    {
        $this->adapters = array_values($adapters);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function configure(Hooks $hooks, array $config): void
    {
        $adapters = $this->adapters;

        foreach ($config as $adapter => $settings) {
            if (!is_a($adapter, ConfigurableAdapterInterface::class, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid adapter "%s" for %s. Expected the name of a class implementing %s.',
                    $adapter,
                    static::class,
                    ConfigurableAdapterInterface::class,
                ));
            }
            if (!is_array($settings)) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid settings %s for the adapter "%s" of %s. Expected an array.',
                    get_debug_type($settings),
                    $adapter,
                    static::class,
                ));
            }

            $adapters[] = $adapter::fromConfig($settings, $this->logger);
        }

        if ($adapters === []) {
            throw new \InvalidArgumentException(sprintf(
                '%s has no adapter. Pass one to the constructor, or name one in its settings.',
                static::class,
            ));
        }

        (new Introspector())->addAdapter(...$adapters)->register($hooks);
    }
}
