<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures\InventoryVersion;

use OpenApi\Spec as OA;

/**
 * A response with a `const` schema, which OpenAPI 3.0 has no keyword for, so a 3.0 build warns
 * and a 3.1 build does not.
 */
#[OA\Info(title: 'Status', version: '1.0')]
class StatusController
{
    #[OA\Operation\Get(path: '/api/orders')]
    #[OA\Response(response: 200, description: 'The status', content: [new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(const: 'ok'))])]
    public function index(): void
    {
    }
}
