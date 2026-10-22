<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A form request whose rules need the user, which there is none of outside a request.
 */
class UserBoundRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // @phpstan-ignore property.nonObject
        return ['name' => 'unique:users,name,' . $this->user()->id];
    }
}
