<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Routing;

use OpenApi\Spec as OA;

/**
 * A route parameter's dispatch constraint, as the schema it honestly implies.
 *
 * The regex constrains what the router matches; it is not a description of the value. It
 * becomes `pattern`, and `type` only where the shape is unambiguous. Where it is not, and the
 * handler's signature types the parameter, the type is left to the pipeline to read from
 * there; otherwise it is `string`. Extend to recognise more shapes, or to map a project's own
 * constraint conventions.
 */
class Constraint
{
    /** @var list<string> the patterns that admit only an integer */
    protected array $integer = ['[0-9]+', '\d+', '[\d]+', '[0-9]*', '\d*'];

    /**
     * @param bool $typed whether the handler's signature types the parameter
     */
    public function schema(?string $pattern, bool $typed = false): OA\Schema
    {
        return new OA\Schema(
            type: $this->type($pattern, $typed),
            pattern: $pattern,
        );
    }

    protected function type(?string $pattern, bool $typed = false): ?string
    {
        if ($pattern !== null && in_array($pattern, $this->integer, true)) {
            return 'integer';
        }

        return $typed ? null : 'string';
    }
}
