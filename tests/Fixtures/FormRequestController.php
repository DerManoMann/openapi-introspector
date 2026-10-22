<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use OpenApi\Spec as OA;

/**
 * Handlers taking a form request, with and without attributes describing the input.
 */
class FormRequestController
{
    public function store(StorePostRequest $request): void
    {
    }

    public function search(SearchPostsRequest $request): void
    {
    }

    public function rename(UserBoundRequest $request): void
    {
    }

    #[OA\RequestBody(content: new OA\Schema(properties: ['body' => new OA\Schema(type: 'string')]))]
    #[OA\Response(response: 422, description: 'Invalid post')]
    public function documented(StorePostRequest $request): void
    {
    }
}
