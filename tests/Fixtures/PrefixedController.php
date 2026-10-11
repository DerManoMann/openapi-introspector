<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use OpenApi\Spec as OA;

/**
 * A controller under a path prefix, which the pipeline puts in front of every operation it declares.
 */
#[OA\PathItem(prefix: '/api')]
class PrefixedController
{
    public function show(int $id): void
    {
    }

    #[OA\Response(response: 200, description: 'All users')]
    public function index(): void
    {
    }
}
