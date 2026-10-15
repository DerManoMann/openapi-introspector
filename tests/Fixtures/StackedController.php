<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use OpenApi\Spec as OA;

/**
 * A controller declaring operations for several routes on one handler method, and one at a path
 * relative to a server URL, as an API controller written for an `/api` server would.
 */
class StackedController
{
    #[OA\Operation\Get(path: '/rooms', summary: 'List rooms', responses: [new OA\Response(response: 200, description: 'The rooms')])]
    #[OA\Operation\Get(path: '/rooms/reservations/{id}', summary: 'Get a reservation', responses: [new OA\Response(response: 200, description: 'The reservation')])]
    public function rooms(): void
    {
    }

    public function reservation(int $id): void
    {
    }

    #[OA\Operation\Get(path: '/settings/{id}', summary: 'Get a setting', responses: [new OA\Response(response: 200, description: 'The setting')])]
    public function metadata(): void
    {
    }

    #[OA\Operation\Get(path: '/accounts', summary: 'List accounts', responses: [new OA\Response(response: 200, description: 'The accounts')])]
    public function accounts(): void
    {
    }
}
