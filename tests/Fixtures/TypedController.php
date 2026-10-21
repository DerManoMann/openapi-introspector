<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

/**
 * Route parameters whose only description is the handler's signature.
 */
class TypedController
{
    public function number(int $id): void
    {
    }

    public function text(string $slug): void
    {
    }

    public function bound(UsersController $user): void
    {
    }

    public function untyped($id): void
    {
    }
}
