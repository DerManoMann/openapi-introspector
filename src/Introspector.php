<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

use Radebatz\OpenApi\Introspector\Routing\RouteIntrospector;

class_alias(RouteIntrospector::class, Introspector::class);

if (false) {
    /**
     * @deprecated since 1.5, removed in 2.0 - use {@see RouteIntrospector}
     */
    final class Introspector
    {
    }
}
