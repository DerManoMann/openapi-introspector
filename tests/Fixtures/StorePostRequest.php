<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A form request with a rule of each kind the introspector reads.
 */
class StorePostRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:120',
            'status' => ['required', Rule::in(['draft', 'published'])],
            'tags' => 'array|max:5',
            'tags.*' => 'string',
            'author.email' => 'required|email',
        ];
    }
}
