<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use OpenApi\Spec as OA;

/**
 * A controller whose operations attributes describe, one of them on a path no route serves.
 */
class AttributedController
{
    #[OA\Operation\Get(path: '/users', summary: 'All the users', responses: [
        new OA\Response(response: 200, description: 'All users'),
    ])]
    public function index(): void
    {
    }

    #[OA\Operation\Get(path: '/legacy', responses: [
        new OA\Response(response: 200, description: 'Still documented'),
    ])]
    public function legacy(): void
    {
    }
}
