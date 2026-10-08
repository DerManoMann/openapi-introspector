<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

use OpenApi\Augmenter\Group;
use OpenApi\Specification;
use OpenApi\Utils\PipeInterface;

/**
 * Runs the introspection as the first augmenter, so every other augmenter sees the operations
 * already completed from their routes.
 *
 * @implements PipeInterface<Specification>
 *
 * @internal
 */
final readonly class IntrospectionAugmenter implements PipeInterface
{
    public function __construct(private Introspector $introspector)
    {
    }

    public function __invoke(mixed $payload): mixed
    {
        if ($payload instanceof Specification) {
            $this->introspector->introspect($payload);
        }

        return null;
    }

    public function group(): Group
    {
        return Group::Resolve;
    }
}
