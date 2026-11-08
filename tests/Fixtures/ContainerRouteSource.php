<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use Illuminate\Container\Container;
use Radebatz\OpenApi\Introspector\Routing\Laravel\RouteSource;

/**
 * A route source that cannot be created from its class name, since it needs the container.
 */
class ContainerRouteSource extends RouteSource
{
    public function __construct(Container $container)
    {
        parent::__construct($container);
    }
}
