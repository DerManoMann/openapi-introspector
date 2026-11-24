<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures\Inventory;

use OpenApi\Spec as OA;

/**
 * Attributes for one routed operation and one no route serves. The title is a constant the
 * L5-Swagger configuration defines.
 */
#[OA\Info(title: INVENTORY_TITLE, version: '1.0')]
class OrdersController
{
    #[OA\Operation\Get(path: '/api/orders')]
    #[OA\Response(response: 200, description: 'All orders')]
    public function index(): void
    {
    }

    #[OA\Operation\Get(path: '/api/orders/archive')]
    #[OA\Response(response: 200, description: 'Archived orders')]
    public function archive(): void
    {
    }
}
