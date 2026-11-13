<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Integrations\L5Swagger;

use Illuminate\Routing\Route;
use L5Swagger\CustomBuilderInterface;
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use Psr\Log\LoggerInterface;
use Radebatz\OpenApi\Introspector\Routing\Laravel\Authentication;
use Radebatz\OpenApi\Introspector\Routing\Laravel\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Routing\Laravel\RouteSource;
use Radebatz\OpenApi\Introspector\Routing\LazyRoutes;
use Radebatz\OpenApi\Introspector\Routing\RouteIntrospector;

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
     * configures, such as more augmenters.
     */
    protected function builder(): Builder
    {
        $logger = $this->logger();

        // swagger-php 7.3 takes the logger in the constructor, and deprecates `setLogger()`
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists(Builder::class, 'withPlugins')) {
            return (new Builder($logger))->setMode($this->mode());
        }

        $builder = (new Builder())->setMode($this->mode());

        return $logger instanceof LoggerInterface ? $builder->setLogger($logger) : $builder;
    }

    /**
     * Hybrid, so the attributes an L5-Swagger project already has are read as they are.
     */
    protected function mode(): Mode
    {
        return Mode::HYBRID;
    }

    /**
     * The logger the builder and the adapter report to, none by default.
     */
    protected function logger(): ?LoggerInterface
    {
        return null;
    }

    protected function introspector(): RouteIntrospector
    {
        return (new RouteIntrospector())->addAdapter(new LaravelAdapter(
            $this->routes(),
            logger: $this->logger(),
            authentication: new Authentication($this->schemes(), (new RouteSource())->defaultGuard(...)),
        ));
    }

    /**
     * The security scheme each guard stands for, such as `['sanctum' => 'bearerAuth']`, none by
     * default. A route whose guards all map gets their security requirements.
     *
     * @return array<string, string>
     */
    protected function schemes(): array
    {
        return [];
    }

    /**
     * The routes in the `api` middleware group, which leaves out the application's web pages
     * and most routes packages add, L5-Swagger's own among them with its default config. They
     * are read when the document is built.
     *
     * @return iterable<Route>
     */
    protected function routes(): iterable
    {
        return new LazyRoutes(static fn (): array => (new RouteSource())->routes(['api']));
    }
}
