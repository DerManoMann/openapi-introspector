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
 * `application` setting of {@see LaravelAdapter::fromConfig()} names it. It is created with no
 * arguments there.
 */
class Application
{
    /**
     * @param Container|null $container the application's container, the global instance by default
     */
    public function __construct(protected ?Container $container = null)
    {
    }

    /**
     * The routes in a middleware group, which for `api` leaves out the application's web pages and
     * most routes packages add.
     *
     * @return list<Route>
     */
    public function routes(string $middleware): array
    {
        return array_values(array_filter(
            $this->container()->make(Router::class)->getRoutes()->getRoutes(),
            static fn (Route $route): bool => in_array($middleware, $route->middleware(), true),
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
