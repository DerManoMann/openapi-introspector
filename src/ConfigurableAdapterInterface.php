<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

use Psr\Log\LoggerInterface;

/**
 * An adapter that can create itself from plain settings, so configuration can name it.
 *
 * The adapter finds what it reads on its own, such as the framework's router, and the settings
 * only adjust its defaults. An adapter that needs objects from the application to be handed to
 * it cannot implement this, and is given to {@see IntrospectorPlugin} as an instance instead.
 */
interface ConfigurableAdapterInterface extends AdapterInterface
{
    /**
     * @param array<string, mixed> $config the adapter's settings, empty for its defaults
     *
     * @throws \InvalidArgumentException for a setting it does not know, or a value it cannot use
     */
    public static function fromConfig(array $config, ?LoggerInterface $logger = null): static;
}
