<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Routing\Laravel;

use Illuminate\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Radebatz\OpenApi\Introspector\Adapters\LaravelAdapter;

/**
 * What the introspector reads from a running Laravel application: the routes to document, and
 * the guard a bare `auth` uses.
 *
 * Code that is created from its class name, and so cannot be handed the router, reads them from
 * here. A subclass changes what is read, such as only the routes of one API version, and the
 * `routeSource` setting of {@see LaravelAdapter::fromConfig()} names it. It is created with no
 * arguments there.
 */
class RouteSource
{
    /**
     * @param Container|null $container the application's container, the global instance by default
     */
    public function __construct(protected ?Container $container = null)
    {
    }

    /**
     * The routes in any of the middleware groups. For `api` that leaves out the application's web
     * pages and most routes packages add.
     *
     * @param list<string> $middleware
     *
     * @return list<Route>
     */
    public function routes(array $middleware): array
    {
        return array_values(array_filter(
            $this->container()->make(Router::class)->getRoutes()->getRoutes(),
            static fn (Route $route): bool => array_intersect($middleware, array_filter($route->middleware(), is_string(...))) !== [],
        ));
    }

    /**
     * The guard a bare `auth` uses: the application's `auth.defaults.guard`, `web` where it sets none.
     */
    public function defaultGuard(): string
    {
        $container = $this->container();
        $guard = $container->bound('config') ? $container->make('config')->get('auth.defaults.guard') : null;

        return is_string($guard) && $guard !== '' ? $guard : 'web';
    }

    protected function container(): Container
    {
        return $this->container ?? Container::getInstance();
    }
}
