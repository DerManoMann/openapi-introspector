<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests;

use OpenApi\Builder;
use OpenApi\Builder\Mode;
use OpenApi\Spec as OA;
use OpenApi\Specification;
use Orchestra\Testbench\TestCase;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;
use Radebatz\OpenApi\Introspector\IntrospectorPlugin;
use Radebatz\OpenApi\Introspector\LaravelDataPlugin;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\LaravelData\PlainTagController;
use Radebatz\OpenApi\Introspector\Tests\Fixtures\LaravelData\TagController;
use Spatie\LaravelData\LaravelDataServiceProvider;

/**
 * Data objects in a booted Laravel application, as an artisan command sees them.
 */
final class LaravelDataPluginTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataServiceProvider::class];
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $result = (new Builder())
            ->setMode(Mode::SPEC)
            ->addSource(new \ReflectionClass(TagController::class))
            ->withSpecification(fn (Specification $specification): Specification => $specification->add(new OA\Info(title: 'Data', version: '1.0.0')))
            ->withPlugins([LaravelDataPlugin::class])
            ->build();

        $this->assertSame([], array_column(array_filter($result->log(), fn (array $entry): bool => $entry['level'] !== 'debug' && $entry['level'] !== 'info'), 'message'));

        return $result->toArray();
    }

    public function testADataClassIsDescribedUnderItsOutputNames(): void
    {
        $schema = $this->document()['components']['schemas']['UserData'];

        $this->assertSame(['first_name', 'email', 'age', 'role', 'tags', 'main_tag', 'team_id'], array_keys($schema['properties']));
        $this->assertSame(['type' => 'string', 'maxLength' => 50], $schema['properties']['first_name']);
        $this->assertSame(['type' => ['string', 'null'], 'format' => 'email'], $schema['properties']['email']);
        $this->assertSame(['type' => 'integer', 'minimum' => 18], $schema['properties']['age']);
        $this->assertSame(['admin', 'editor'], $schema['properties']['role']['enum']);
        $this->assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TagData']], $schema['properties']['tags']);
        $this->assertSame('#/components/schemas/TagData', $schema['properties']['main_tag']['$ref'] ?? $schema['properties']['main_tag']['allOf'][0]['$ref'] ?? null);
        $this->assertSame(['type' => 'integer'], $schema['properties']['team_id'], 'exists has no keyword');
        $this->assertSame(['first_name', 'age', 'role', 'main_tag', 'team_id'], $schema['required']);
    }

    public function testTheInputHasASchemaOfItsOwnWhereItDiffers(): void
    {
        $document = $this->document();
        $operation = $document['paths']['/users']['post'];
        $ref = $operation['requestBody']['content']['application/json']['schema']['$ref'];

        $this->assertNotSame('#/components/schemas/UserData', $ref);
        $input = $document['components']['schemas'][substr($ref, strlen('#/components/schemas/'))];
        $this->assertArrayHasKey('password', $input['properties'], 'hidden is output only');
        $this->assertSame('#/components/schemas/UserData', $operation['responses'][200]['content']['application/json']['schema']['$ref']);
        $this->assertArrayHasKey(422, $operation['responses']);
    }

    public function testOneSchemaWhereRequestAndResponseAgree(): void
    {
        $operation = $this->document()['paths']['/tags']['post'];

        $this->assertSame('#/components/schemas/TagData', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertSame('#/components/schemas/TagData', $operation['responses'][200]['content']['application/json']['schema']['$ref']);
    }

    public function testADeclaredRequestBodyWinsAndTheValidationResponseIsStillAdded(): void
    {
        $operation = $this->document()['paths']['/tags']['put'];

        $this->assertSame('Declared', $operation['requestBody']['description']);
        $this->assertArrayHasKey(422, $operation['responses']);
    }

    public function testADataClassThatHoldsItselfIsDescribedOnce(): void
    {
        $schema = $this->document()['components']['schemas']['NodeData'];

        $this->assertSame('#/components/schemas/NodeData', $schema['properties']['children']['items']['$ref']);
        $this->assertSame(['name', 'parent', 'children'], array_keys($schema['properties']));
    }

    public function testARouteTheIntrospectorReadsGetsItsDataObjects(): void
    {
        $this->app['router']->middleware('api')->post('/tags', [PlainTagController::class, 'store']);

        $result = (new Builder())
            ->setMode(Mode::SPEC)
            ->withSpecification(fn (Specification $specification): Specification => $specification->add(new OA\Info(title: 'Data', version: '1.0.0')))
            ->withPlugins([IntrospectorPlugin::class => [LaravelAdapter::class => []], LaravelDataPlugin::class])
            ->build();
        $operation = $result->toArray()['paths']['/tags']['post'];

        $this->assertSame('#/components/schemas/TagData', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertSame('#/components/schemas/TagData', $operation['responses'][200]['content']['application/json']['schema']['$ref']);
    }
}
