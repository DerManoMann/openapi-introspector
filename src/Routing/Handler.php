<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Routing;

/**
 * Resolves a route's handler to the method reflector the pipeline derives from.
 *
 * The result is always a method: a controller action is that method, and an invokable
 * controller is its `__invoke`. swagger-php derives the operation id from the method, the
 * path and the reflected name together, so two invokables never collide. A closure has
 * nothing to reflect on that names it, so it yields `null` and the route is contributed bare.
 *
 * The operation carries the method reflector, for the docblock's summary and description. A
 * route parameter carries the signature's parameter of the same name, so the pipeline reads its
 * type, but only for a scalar. A class type is a binding the framework resolves from the
 * route's value, such as Laravel's route-model binding, and says nothing about the value in the
 * path. An enum is left out too, since swagger-php reads nothing from an enum type unless the
 * enum has a schema of its own, and the parameter would lose the `string` it has without one. Parameters the container injects match no placeholder, so they stay
 * invisible. Extend to resolve a project's own handler conventions, such as a container id or a
 * command class.
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

    /**
     * The handler's parameter for a route placeholder, where its type describes the path value.
     */
    public function parameter(?\ReflectionMethod $method, string $name): ?\ReflectionParameter
    {
        foreach ($method?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() !== $name) {
                continue;
            }

            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType) {
                return null;
            }

            return in_array($type->getName(), ['int', 'float', 'string', 'bool'], true) ? $parameter : null;
        }

        return null;
    }
}
