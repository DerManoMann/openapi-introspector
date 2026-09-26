<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

/**
 * How an inventory entry's operation reached the document.
 */
enum Status: string
{
    /** Attributes describe it and a route serves it; the adapter's operation stood aside. */
    case Matched = 'matched';
    /** A route serves it and nothing else describes it; the adapter's operation went in. */
    case Introspected = 'introspected';
    /** Attributes describe it and no route serves it. */
    case Unrouted = 'unrouted';
}
