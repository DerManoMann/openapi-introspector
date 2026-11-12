<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures\LaravelData;

use OpenApi\Spec as OA;

class TagController
{
    #[OA\Operation\Post(path: '/users', operationId: 'createUser')]
    public function store(UserData $user): UserData
    {
        return $user;
    }

    #[OA\Operation\Post(path: '/tags', operationId: 'createTag')]
    public function tag(TagData $tag): TagData
    {
        return $tag;
    }

    #[OA\Operation\Put(path: '/tags', operationId: 'replaceTag', requestBody: new OA\RequestBody(description: 'Declared', content: new OA\Schema(type: 'object')))]
    public function replace(TagData $tag): void
    {
    }

    #[OA\Operation\Post(path: '/nodes', operationId: 'createNode')]
    public function node(NodeData $node): NodeData
    {
        return $node;
    }
}
