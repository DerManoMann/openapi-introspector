<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

use OpenApi\Spec as OA;

/**
 * Reads what one framework knows about the API it serves.
 *
 * An adapter yields one partial operation per method and path the framework routes, each with
 * a path and a method, and says nothing about what it cannot see. Where the route dispatches
 * to a class method, the operation carries that method's reflector, and the pipeline derives
 * the rest from it. Anything reachable by reflection on an attribute is not an adapter's job;
 * swagger-php's translators cover that.
 */
interface AdapterInterface
{
    /**
     * @return iterable<OA\Operation>
     */
    public function operations(): iterable;
}
