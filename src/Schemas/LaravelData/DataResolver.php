<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Schemas\LaravelData;

use Illuminate\Container\Container;
use OpenApi\Assembler;
use OpenApi\Contracts\KeyResolverInterface;
use OpenApi\Resolver\Concerns\KeyResolverTrait;
use OpenApi\Spec as OA;
use OpenApi\Specification\ComponentKey;
use OpenApi\Undefined;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Radebatz\OpenApi\Introspector\Routing\Laravel\ValidationRules;
use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\Support\DataClass;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataProperty;

/**
 * Describes a spatie/laravel-data class that has no OpenAPI attributes, from what laravel-data
 * knows about it.
 *
 * The properties are the ones the data object outputs, under their mapped names, without the
 * `#[Hidden]` ones. Each carries its reflector, so swagger-php reads the type as it does for any
 * property, a nested data object becoming a reference to its own component. A collection takes
 * its item class from `#[DataCollectionOf]` or the docblock, as laravel-data does. The constraints
 * and `required` come from the rules laravel-data validates the class with, mapped as a form
 * request's are; a rule with no OpenAPI keyword, such as `exists`, is left out.
 *
 * Where the request and the response differ, through mapped names, `#[Hidden]` or `#[Computed]`,
 * a key with the qualifier `direction: input` describes the input: the properties laravel-data
 * reads, under their input names, without the computed ones. `DataOperations` asks for it.
 *
 * It needs laravel-data's `DataConfig` from Laravel's container, so the application has to be
 * booted, as it is in an artisan command. Without it, the class is left to the next resolver,
 * with a warning.
 */
class DataResolver implements KeyResolverInterface, LoggerAwareInterface
{
    use KeyResolverTrait;
    use LoggerAwareTrait;

    /** The keywords a validation rule adds to a property, beside the type the property declares. */
    protected const CONSTRAINTS = ['format', 'enum', 'minLength', 'maxLength', 'minimum', 'maximum', 'minItems', 'maxItems'];

    public function __construct(protected ValidationRules $validationRules = new ValidationRules())
    {
    }

    /**
     * Whether the request and the response of a data class differ, so the input needs a schema of its own.
     *
     * @param class-string $class
     */
    public static function differs(string $class): bool
    {
        $dataClass = self::dataClass($class);

        return $dataClass instanceof DataClass && $dataClass->properties->contains(
            static fn (DataProperty $property): bool => $property->hidden || $property->computed || $property->inputMappedName !== $property->outputMappedName
        );
    }

    public function resolveKey(ComponentKey $key, Assembler $assembler): bool
    {
        $class = $key->class;
        if (!is_a($class, BaseData::class, true)) {
            return false;
        }

        $input = match ($key->qualifiers) {
            [] => false,
            ['direction' => 'input'] => true,
            default => null,
        };
        if ($input === null) {
            return false;
        }

        $schema = $this->describe($class, $input);
        if (!$schema instanceof OA\Schema) {
            return false;
        }

        $assembler->getSpecification()->add($schema);

        return true;
    }

    /**
     * The component for a data class, as output or as input, or `null` where laravel-data cannot
     * be asked.
     *
     * @param class-string $class
     */
    public function describe(string $class, bool $input = false): ?OA\Schema
    {
        $dataClass = self::dataClass($class);
        if (!$dataClass instanceof DataClass) {
            $this->logger?->warning(sprintf('Cannot describe %s: laravel-data is not set up in the container, so the application is not booted', $class));

            return null;
        }

        $schema = $this->schema($dataClass, $this->fields($class), $input);
        $schema->setReflector(new \ReflectionClass($class));
        if ($input) {
            $schema->setComponentKey(self::key($class, true));
        }

        return $schema;
    }

    /**
     * The key of a data class's component: plain for the output, or the one schema both share,
     * and with `direction: input` for the input where it differs.
     *
     * @param class-string $class
     */
    public static function key(string $class, bool $input = false): ComponentKey
    {
        return new ComponentKey($class, $input ? ['direction' => 'input'] : []);
    }

    /**
     * The data classes a data class's properties hold, as objects or in collections.
     *
     * @param class-string $class
     *
     * @return list<class-string>
     */
    public static function nested(string $class): array
    {
        $dataClass = self::dataClass($class);
        if (!$dataClass instanceof DataClass) {
            return [];
        }

        $nested = [];
        foreach ($dataClass->properties as $property) {
            if ($property->type->dataClass !== null && class_exists($property->type->dataClass)) {
                $nested[] = $property->type->dataClass;
            }
        }

        return array_values(array_unique($nested));
    }

    /**
     * @param array<string, array{OA\Schema, bool}> $fields each input field's schema from the rules, and whether it is required
     */
    protected function schema(DataClass $dataClass, array $fields, bool $input): OA\Schema
    {
        $properties = [];
        $required = [];
        foreach ($dataClass->properties as $property) {
            if ($input ? $property->computed : $property->hidden) {
                continue;
            }

            $name = ($input ? $property->inputMappedName : $property->outputMappedName) ?? $property->name;
            [$constraints, $isRequired] = $fields[$property->inputMappedName ?? $property->name] ?? [null, false];

            $properties[] = $this->property($name, $property, $constraints);
            if ($isRequired) {
                $required[] = $name;
            }
        }

        return new OA\Schema(type: 'object', properties: $properties, required: $required ?: null);
    }

    protected function property(string $name, DataProperty $property, ?OA\Schema $constraints): OA\Property
    {
        $arguments = [];
        foreach (self::CONSTRAINTS as $keyword) {
            $value = $constraints?->{$keyword};
            if ($value !== null && $value !== Undefined::UNDEFINED) {
                $arguments[$keyword] = $value;
            }
        }

        $itemClass = $property->type->dataClass;
        if ($itemClass !== null && $property->type->kind->isDataCollectable()) {
            $arguments['type'] = 'array';
            $arguments['items'] = new OA\Schema(ref: $itemClass);
        }

        $attribute = new OA\Property(property: $name, schema: $arguments === [] ? null : new OA\Schema(...$arguments));
        if (class_exists($property->className) && property_exists($property->className, $property->name)) {
            $attribute->setReflector(new \ReflectionProperty($property->className, $property->name));
        }

        return $attribute;
    }

    /**
     * The input fields laravel-data validates, read as `LaravelAdapter` reads a form request's rules.
     *
     * @param class-string $class
     *
     * @return array<string, array{OA\Schema, bool}>
     */
    protected function fields(string $class): array
    {
        if (!method_exists($class, 'getValidationRules')) {
            return [];
        }

        try {
            $rules = $class::getValidationRules([]);
        } catch (\Throwable $throwable) {
            $this->logger?->warning(sprintf('Cannot read the validation rules of %s: %s', $class, $throwable->getMessage()));

            return [];
        }

        return $this->validationRules->fields($rules);
    }

    /**
     * @param class-string $class
     */
    protected static function dataClass(string $class): ?DataClass
    {
        try {
            return Container::getInstance()->make(DataConfig::class)->getDataClass($class);
        } catch (\Throwable) {
            return null;
        }
    }
}
