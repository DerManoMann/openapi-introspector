<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures\InventoryBroken;

use OpenApi\Spec as OA;

/**
 * An attribute using a constant nothing defines, so the class cannot be loaded and is skipped.
 */
class BrokenController
{
    #[OA\Operation\Get(path: INVENTORY_UNDEFINED_PATH)]
    #[OA\Response(response: 200, description: 'Never read')]
    public function index(): void
    {
    }
}
