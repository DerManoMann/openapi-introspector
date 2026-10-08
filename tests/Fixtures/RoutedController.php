<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use OpenApi\Spec as OA;

/**
 * A controller whose attributes add to what its routes give rather than restating it.
 */
#[OA\Parameter(component: 'UserId', name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
class RoutedController
{
    /**
     * Show one user.
     */
    #[OA\Response(response: 200, description: 'The user')]
    public function show(int $id): void
    {
    }

    #[OA\Operation\Put(summary: 'Replace a user')]
    #[OA\Response(response: 204, description: 'Replaced')]
    public function update(
        #[OA\Parameter\Path(description: 'The user id', schema: new OA\Schema(type: 'string'))]
        string $id,
    ): void {
    }

    #[OA\Operation\Delete(parameters: [new OA\Parameter(ref: '#/components/parameters/UserId')])]
    #[OA\Response(response: 204, description: 'Deleted')]
    public function destroy(int $id): void
    {
    }

    #[OA\Operation\Get(path: '/accounts/{id}', tags: ['Accounts'])]
    #[OA\Response(response: 200, description: 'The account')]
    public function account(): void
    {
    }

    #[OA\Operation\Get(path: '/items', summary: 'The first page')]
    #[OA\Response(response: 200, description: 'Items')]
    public function items(?int $page = null): void
    {
    }
}
