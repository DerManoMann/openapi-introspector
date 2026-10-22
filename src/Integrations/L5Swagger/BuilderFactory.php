<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Integrations\L5Swagger;

use Illuminate\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use L5Swagger\CustomBuilderInterface;
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Introspector;

/**
 * An L5-Swagger `builder_factory` that adds the application's routes to the document.
 *
 * L5-Swagger creates the factory from its class name, with no arguments, and adds its
 * `annotations` paths, the OpenAPI version and a base path to the builder this returns. So the
 * factory reads the router from the container, and a project changes what it does by
 * extending it.
 */
class BuilderFactory implements CustomBuilderInterface
{
    public function create(): Builder
    {
        return $this->introspector()->register($this->builder());
    }

    /**
     * The builder before the introspector is registered on it, for anything else a project
     * configures, such as a logger or more augmenters.
     */
    protected function builder(): Builder
    {
        return (new Builder())->setMode($this->mode());
    }

    /**
     * Hybrid, so the attributes an L5-Swagger project already has are read as they are.
     */
    protected function mode(): Mode
    {
        return Mode::HYBRID;
    }

    protected function introspector(): Introspector
    {
        return (new Introspector())->addAdapter(new LaravelAdapter($this->routes()));
    }

    /**
     * The routes in the `api` middleware group, which leaves out the application's web pages
     * and most routes packages add, L5-Swagger's own among them with its default config.
     *
     * @return iterable<Route>
     */
    protected function routes(): iterable
    {
        return array_filter(
            Container::getInstance()->make(Router::class)->getRoutes()->getRoutes(),
            static fn (Route $route): bool => in_array('api', $route->middleware(), true),
        );
    }
}
