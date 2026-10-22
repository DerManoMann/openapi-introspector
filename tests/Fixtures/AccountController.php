<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use OpenApi\Spec as OA;

/**
 * Handlers behind authentication, with and without attributes describing it.
 */
class AccountController
{
    public function show(): void
    {
    }

    #[OA\Operation\Get(security: [])]
    public function public(): void
    {
    }

    #[OA\Response(response: 401, description: 'Log in first')]
    public function described(): void
    {
    }
}
