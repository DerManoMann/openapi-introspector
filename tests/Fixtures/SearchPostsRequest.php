<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A form request for a query.
 */
class SearchPostsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => 'required|string',
            'page' => 'integer|min:1',
        ];
    }
}
