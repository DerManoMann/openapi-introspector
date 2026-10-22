<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use OpenApi\Spec as OA;

/**
 * Handlers taking a bound model, with and without attributes describing a not found response.
 */
class PostsController
{
    public function show(Post $post): void
    {
    }

    #[OA\Response(response: 200, description: 'The post')]
    public function described(Post $post): void
    {
    }

    #[OA\Response(response: 404, description: 'No such post')]
    public function own(Post $post): void
    {
    }

    #[OA\Response(response: '4XX', description: 'Client error')]
    public function range(Post $post): void
    {
    }

    public function snake(Post $blogPost): void
    {
    }

    public function defaulted(?Post $post = null): void
    {
    }
}
