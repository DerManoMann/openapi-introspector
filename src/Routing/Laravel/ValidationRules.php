<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Routing\Laravel;

use OpenApi\Spec as OA;

/**
 * Turns Laravel validation rules into schemas, one per top-level field.
 *
 * Only rules that map onto a schema without guessing are read; any other rule, a rule object
 * that is not a string, or a closure says nothing, so a field without a type rule keeps its type
 * open. Dotted keys nest: `items.*.id` is the `id` of each item in `items`. Extend for a
 * project's own rules.
 */
class ValidationRules
{
    private const TYPES = [
        'string' => 'string',
        'integer' => 'integer',
        'numeric' => 'number',
        'boolean' => 'boolean',
        'array' => 'array',
        'date' => 'string',
    ];

    private const FORMATS = [
        'email' => 'email',
        'url' => 'uri',
        'uuid' => 'uuid',
    ];

    /**
     * @param array<string, mixed> $rules as `rules()` returns them
     *
     * @return array<string, array{OA\Schema, bool}> each top-level field's schema, and whether it is required
     */
    public function fields(array $rules): array
    {
        $fields = [];
        foreach ($this->group($rules) as $name => $entries) {
            // a rule on the whole input, such as `*.id` for a list, has no field to go on
            if ($name === '*') {
                continue;
            }

            $fields[$name] = [$this->schema($entries), isset($this->parse($entries[''] ?? [])['required'])];
        }

        return $fields;
    }

    /**
     * A field's rules by name, each with its parameters.
     *
     * @return array<string, list<string>>
     */
    protected function parse(mixed $rules): array
    {
        // Laravel splits a rule string on `|`, so a `regex` with one has to be given as an array
        $list = is_string($rules) ? explode('|', $rules) : (is_array($rules) ? $rules : [$rules]);

        $parsed = [];
        foreach ($list as $rule) {
            if ($rule instanceof \Stringable) {
                // such as `Rule::in()`, which reads as `in:"a","b"`
                $rule = (string) $rule;
            }
            if (!is_string($rule) || $rule === '') {
                continue;
            }

            [$name, $parameters] = array_pad(explode(':', $rule, 2), 2, null);
            $parsed[strtolower(trim($name))] = $parameters === null ? [] : array_map(strval(...), str_getcsv($parameters, escape: ''));
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $entries a field's own rules under `''`, and its children's by their remaining key
     */
    private function schema(array $entries): OA\Schema
    {
        $rules = $this->parse($entries[''] ?? []);
        unset($entries['']);
        $type = null;
        foreach (self::TYPES as $rule => $schemaType) {
            if (isset($rules[$rule])) {
                $type = $schemaType;
                break;
            }
        }

        $arguments = [];
        $children = $this->group($entries);
        if (isset($children['*'])) {
            $type = 'array';
            $arguments['items'] = $this->schema($children['*']);
            unset($children['*']);
        }
        if ($children !== []) {
            // an array with named keys is an object
            $type = 'object';
            $properties = [];
            $required = [];
            foreach ($children as $name => $child) {
                $properties[$name] = $this->schema($child);
                if (isset($this->parse($child[''] ?? [])['required'])) {
                    $required[] = $name;
                }
            }
            $arguments['properties'] = $properties;
            $arguments['required'] = $required ?: null;
            unset($arguments['items']);
        }

        if ($type !== null) {
            $arguments['type'] = $type;
        }
        foreach (self::FORMATS as $rule => $format) {
            if (isset($rules[$rule])) {
                $arguments['type'] ??= 'string';
                $arguments['format'] = $format;
            }
        }
        if (isset($rules['nullable'])) {
            $arguments['nullable'] = true;
        }
        if (isset($rules['in'])) {
            $arguments['enum'] = in_array($type, ['integer', 'number'], true)
                ? array_values(array_map($this->number(...), array_filter($rules['in'], is_numeric(...))))
                : $rules['in'];
        }

        // the size rules measure a string's length, a number's value and an array's items, so
        // without a type they say nothing
        [$min, $max] = $this->bounds($rules);
        $bounded = match ($type ?? $arguments['type'] ?? null) {
            'string' => ['minLength', 'maxLength'],
            'integer', 'number' => ['minimum', 'maximum'],
            'array' => ['minItems', 'maxItems'],
            default => null,
        };
        if ($bounded !== null) {
            $integral = $bounded[0] !== 'minimum';
            if ($min !== null) {
                $arguments[$bounded[0]] = $integral ? (int) $min : $this->number($min);
            }
            if ($max !== null) {
                $arguments[$bounded[1]] = $integral ? (int) $max : $this->number($max);
            }
        }

        return new OA\Schema(...$arguments);
    }

    /**
     * @param array<string, list<string>> $rules
     *
     * @return array{string|null, string|null}
     */
    private function bounds(array $rules): array
    {
        $min = $rules['min'][0] ?? $rules['between'][0] ?? null;
        $max = $rules['max'][0] ?? $rules['between'][1] ?? null;

        return [
            is_numeric($min) ? $min : null,
            is_numeric($max) ? $max : null,
        ];
    }

    /**
     * Rules keyed by their first segment, each with the rest of its key, `''` for none.
     *
     * @param array<array-key, mixed> $rules
     *
     * @return array<string, array<string, mixed>>
     */
    private function group(array $rules): array
    {
        $grouped = [];
        foreach ($rules as $key => $value) {
            [$head, $rest] = array_pad(explode('.', (string) $key, 2), 2, '');
            $grouped[$head][$rest] = $value;
        }

        return $grouped;
    }

    private function number(string $value): int|float
    {
        return (string) (int) $value === $value ? (int) $value : (float) $value;
    }
}
