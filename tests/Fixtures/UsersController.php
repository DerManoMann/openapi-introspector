<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

/**
 * A controller carrying no attributes, so everything the document says about it is derived.
 */
class UsersController
{
    /**
     * List the users.
     *
     * @return array<int, mixed>
     */
    public function index(): array
    {
        return [];
    }

    /**
     * Show one user.
     *
     * @return array<string, mixed>
     */
    public function show(int $id): array
    {
        return ['id' => $id];
    }
}
