<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use OpenApi\Spec as OA;

/**
 * A handler routed for several methods on one path, described by bare attributes only.
 */
class MultiMethodUserController
{
    #[OA\Response(response: 204, description: 'Changed')]
    public function change(
        #[OA\Parameter\Path(description: 'The user id')]
        int $id,
    ): void {
    }
}
