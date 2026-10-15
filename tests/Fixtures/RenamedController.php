<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use OpenApi\Spec as OA;

/**
 * A controller whose attributes name the path placeholders differently from its routes.
 */
class RenamedController
{
    #[OA\Operation\Get(path: '/events/{id}')]
    #[OA\Response(response: 200, description: 'The event')]
    public function show(
        #[OA\Parameter\Path(description: 'The event id', schema: new OA\Schema(type: 'integer'))]
        int $id,
    ): void {
    }

    #[OA\Operation\Delete(path: '/events/{id}')]
    #[OA\Response(response: 204, description: 'Deleted')]
    public function destroy(int $id): void
    {
    }

    #[OA\Operation\Get(path: '/reports/{id}')]
    #[OA\Response(response: 200, description: 'The report')]
    public function report(int $id): void
    {
    }
}
