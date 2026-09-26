<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Routing;

use OpenApi\Spec as OA;

/**
 * A route parameter's dispatch constraint, as the schema it honestly implies.
 *
 * The regex constrains what the router matches; it is not a description of the value. It
 * becomes `pattern`, and `type` only where the shape is unambiguous. Extend to recognise
 * more shapes, or to map a project's own constraint conventions.
 */
class Constraint
{
    /** @var list<string> the patterns that admit only an integer */
    protected array $integer = ['[0-9]+', '\d+', '[\d]+', '[0-9]*', '\d*'];

    public function schema(?string $pattern): OA\Schema
    {
        return new OA\Schema(
            type: $this->type($pattern),
            pattern: $pattern,
        );
    }

    protected function type(?string $pattern): string
    {
        return $pattern !== null && in_array($pattern, $this->integer, true) ? 'integer' : 'string';
    }
}
