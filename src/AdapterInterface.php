<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

class_alias(Routing\AdapterInterface::class, AdapterInterface::class);

if (false) {
    /**
     * @deprecated since 1.5, removed in 2.0 - use {@see Routing\AdapterInterface}
     */
    interface AdapterInterface
    {
    }
}
