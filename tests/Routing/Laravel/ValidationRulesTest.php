<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Routing\Laravel;

use Illuminate\Validation\Rule;
use OpenApi\Spec as OA;
use PHPUnit\Framework\TestCase;
use Radebatz\OpenApi\Introspector\Routing\Laravel\ValidationRules;

final class ValidationRulesTest extends TestCase
{
    public function testTypeRulesBecomeTheSchemaType(): void
    {
        $fields = (new ValidationRules())->fields([
            'name' => 'string',
            'age' => 'integer',
            'price' => 'numeric',
            'active' => 'boolean',
            'born' => 'date',
            'free' => 'required',
        ]);

        $this->assertSame('string', $fields['name'][0]->type);
        $this->assertSame('integer', $fields['age'][0]->type);
        $this->assertSame('number', $fields['price'][0]->type);
        $this->assertSame('boolean', $fields['active'][0]->type);
        $this->assertSame('string', $fields['born'][0]->type);
        $this->assertNull($fields['free'][0]->type, 'no type rule, no type');
        $this->assertTrue($fields['free'][1]);
        $this->assertFalse($fields['name'][1]);
    }

    public function testSizeRulesFollowTheType(): void
    {
        $fields = (new ValidationRules())->fields([
            'name' => 'string|min:2|max:10',
            'age' => ['integer', 'between:18,99'],
            'tags' => 'array|max:3',
            'loose' => 'max:5',
        ]);

        $this->assertSame([2, 10], [$fields['name'][0]->minLength, $fields['name'][0]->maxLength]);
        $this->assertSame([18, 99], [$fields['age'][0]->minimum, $fields['age'][0]->maximum]);
        $this->assertSame(3, $fields['tags'][0]->maxItems);
        $this->assertNull($fields['loose'][0]->maxLength, 'without a type, a size says nothing');
        $this->assertNull($fields['loose'][0]->maximum);
    }

    public function testFormatsEnumsAndNullable(): void
    {
        $fields = (new ValidationRules())->fields([
            'email' => 'email',
            'site' => 'nullable|url',
            'id' => 'uuid',
            'status' => [Rule::in(['draft', 'published'])],
            'level' => 'integer|in:1,2,3',
        ]);

        $this->assertSame(['string', 'email'], [$fields['email'][0]->type, $fields['email'][0]->format]);
        $this->assertSame('uri', $fields['site'][0]->format);
        $this->assertTrue($fields['site'][0]->nullable);
        $this->assertSame('uuid', $fields['id'][0]->format);
        $this->assertSame(['draft', 'published'], $fields['status'][0]->enum);
        $this->assertSame([1, 2, 3], $fields['level'][0]->enum);
    }

    public function testDottedKeysNest(): void
    {
        $fields = (new ValidationRules())->fields([
            'items' => 'required|array',
            'items.*.id' => 'required|integer',
            'items.*.note' => 'string',
            'meta.source' => 'string',
            '*.ignored' => 'string',
        ]);

        $this->assertSame(['items', 'meta'], array_keys($fields));
        $items = $fields['items'][0];
        $this->assertSame('array', $items->type);
        $this->assertInstanceOf(OA\Schema::class, $item = $items->items);
        $this->assertSame('object', $item->type);
        $this->assertSame(['id'], $item->required);
        $this->assertSame('object', $fields['meta'][0]->type);
    }

    public function testRulesItCannotReadSayNothing(): void
    {
        $fields = (new ValidationRules())->fields([
            'name' => ['string', static fn (): bool => true, new \stdClass(), 'regex:/^a|b$/'],
        ]);

        $this->assertSame('string', $fields['name'][0]->type);
        $this->assertNull($fields['name'][0]->pattern);
    }
}
