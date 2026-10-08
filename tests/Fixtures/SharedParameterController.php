<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use OpenApi\Spec as OA;

/**
 * A controller declaring its path parameter once, on the path item, for every operation under it.
 */
#[OA\PathItem(parameters: [new OA\Parameter\Path(name: 'id', description: 'The thing id', schema: new OA\Schema(type: 'integer', minimum: 1))])]
class SharedParameterController
{
    #[OA\Response(response: 200, description: 'The thing')]
    public function show(int $id): void
    {
    }
}
