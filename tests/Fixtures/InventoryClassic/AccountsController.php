<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures\InventoryClassic;

use OpenApi\Attributes as OA;

/**
 * An operation described with a classic attribute, which the analyser decides whether to read.
 */
class AccountsController
{
    #[OA\Get(path: '/api/accounts', responses: [new OA\Response(response: 200, description: 'All accounts')])]
    public function index(): void
    {
    }
}
