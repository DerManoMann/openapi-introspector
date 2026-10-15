<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Routing;

/**
 * Takes a leading prefix off a route's path, for documents whose paths are relative to a server
 * URL carrying it: with `/api` as the prefix, `/api/users` is documented as `/users`.
 *
 * Only whole segments come off, so `/apis` keeps its path, and a path without the prefix is left
 * as it is.
 *
 * @internal
 */
final class Prefix
{
    public static function strip(string $prefix, string $path): string
    {
        $prefix = '/' . trim($prefix, '/');
        if ($prefix === '/') {
            return $path;
        }

        if ($path === $prefix) {
            return '/';
        }

        return str_starts_with($path, $prefix . '/') ? substr($path, strlen($prefix)) : $path;
    }
}
