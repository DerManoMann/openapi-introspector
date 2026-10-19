<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use OpenApi\Spec as OA;

/**
 * A controller whose operations are routed for several methods on one path.
 */
class MultiMethodController
{
    #[OA\Operation(summary: 'Save a draft')]
    #[OA\Response(response: 201, description: 'Saved')]
    public function save(): void
    {
    }

    #[OA\Operation\Put(summary: 'Replace a draft')]
    #[OA\Response(response: 204, description: 'Replaced')]
    public function replace(): void
    {
    }
}
