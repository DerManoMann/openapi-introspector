<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

use OpenApi\Augmenter\Group;
use OpenApi\Specification;
use OpenApi\Utils\PipeInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Runs the introspection as the first augmenter, so every other augmenter sees the operations
 * already completed from their routes. It takes the pipeline's logger for what the introspection
 * reports.
 *
 * @implements PipeInterface<Specification>
 *
 * @internal
 */
final class IntrospectionAugmenter implements PipeInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(private readonly Introspector $introspector)
    {
    }

    public function __invoke(mixed $payload): mixed
    {
        if ($payload instanceof Specification) {
            $this->introspector->introspect($payload, $this->logger);
        }

        return null;
    }

    public function group(): Group
    {
        return Group::Resolve;
    }
}
