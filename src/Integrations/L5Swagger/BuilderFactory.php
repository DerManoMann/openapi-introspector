<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Integrations\L5Swagger;

use Illuminate\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use L5Swagger\CustomBuilderInterface;
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use Psr\Log\LoggerInterface;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Introspector;
use Radebatz\OpenApi\Introspector\Routing\Laravel\Authentication;

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
        $builder = (new Builder())->setMode($this->mode());
        $logger = $this->logger();

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

    protected function introspector(): Introspector
    {
        return (new Introspector())->addAdapter(new LaravelAdapter(
            $this->routes(),
            logger: $this->logger(),
            authentication: new Authentication($this->schemes(), $this->defaultGuard()),
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

    /**
     * The guard a bare `auth` uses: the application's `auth.defaults.guard`, `web` where it sets none.
     */
    private function defaultGuard(): string
    {
        $container = Container::getInstance();
        $guard = $container->bound('config') ? $container->make('config')->get('auth.defaults.guard') : null;

        return is_string($guard) && $guard !== '' ? $guard : 'web';
    }
}
