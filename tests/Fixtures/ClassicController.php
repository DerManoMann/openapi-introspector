<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use OpenApi\Attributes as OA;

/**
 * A controller documented with classic attributes, which only hybrid mode reads.
 */
class ClassicController
{
    #[OA\Get(path: '/users/{id}', summary: 'Show a user', responses: [new OA\Response(response: 200, description: 'The user')])]
    public function show(int $id): void
    {
    }

    /**
     * Ping the service.
     */
    public function ping(): void
    {
    }
}
