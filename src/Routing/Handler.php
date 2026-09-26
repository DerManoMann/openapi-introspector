<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Routing;

/**
 * Resolves a route's handler to the method reflector the pipeline derives from.
 *
 * The result is always a method: a controller action is that method, and an invokable
 * controller is its `__invoke`, which swagger-php names `Class::__invoke` so two of them do
 * not collide. A closure has nothing to reflect on that names it, so it yields `null` and the
 * route is contributed bare. Extend to resolve a project's own handler conventions, such as a
 * container id or a command class.
 */
class Handler
{
    public function reflect(?string $class, ?string $method): ?\ReflectionMethod
    {
        if ($class === null || $method === null || !class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        return new \ReflectionMethod($class, $method);
    }

    /**
     * Resolves the callable forms routers accept: `Class:method`, `Class::method`,
     * `Class@method`, `[Class, 'method']`, `[$object, 'method']`, an invokable class name or
     * instance.
     */
    public function reflectCallable(mixed $callable): ?\ReflectionMethod
    {
        if (is_string($callable)) {
            if (preg_match('/^([^:@]+)(?:::?|@)([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)$/', $callable, $matches) === 1) {
                return class_exists($matches[1]) ? $this->reflect($matches[1], $matches[2]) : null;
            }

            return class_exists($callable) ? $this->reflect($callable, '__invoke') : null;
        }

        if (is_array($callable) && count($callable) === 2 && is_string($callable[1])) {
            $class = is_object($callable[0]) ? $callable[0]::class : $callable[0];

            return is_string($class) && class_exists($class) ? $this->reflect($class, $callable[1]) : null;
        }

        if (is_object($callable) && !$callable instanceof \Closure) {
            return $this->reflect($callable::class, '__invoke');
        }

        return null;
    }
}
