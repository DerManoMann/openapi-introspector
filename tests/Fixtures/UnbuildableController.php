<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use Psr\Log\LoggerInterface;

/**
 * A controller that declares middleware the old way and cannot be built without the application.
 */
class UnbuildableController
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @return list<array{middleware: string, options: array<string, mixed>}>
     */
    public function getMiddleware(): array
    {
        return [];
    }

    public function show(): void
    {
        $this->logger->info('shown');
    }
}
