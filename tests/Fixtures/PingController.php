<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

/**
 * An invokable controller, which routers accept as a bare class name.
 */
class PingController
{
    /**
     * Answer a ping.
     */
    public function __invoke(): string
    {
        return 'pong';
    }
}
