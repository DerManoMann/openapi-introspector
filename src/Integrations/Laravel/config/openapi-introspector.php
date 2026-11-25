<?php declare(strict_types=1);

/*
 * Settings for `php artisan openapi:inventory` in an application without L5-Swagger. With
 * L5-Swagger installed, the command follows one of its documentations instead.
 */
return [
    // The directories and files the attributes are read from.
    'sources' => [app_path()],

    // The OpenAPI version the document is built for, such as '3.1.0'. Null builds the version the
    // `OpenApi` attribute declares, or 3.1.0 without one.
    'version' => null,

    // The LaravelAdapter settings, the keys L5-Swagger takes for it under `plugins`. Each is optional,
    // and left out takes the default shown.
    'adapter' => [
        // The middleware group whose routes are documented, or a list of groups.
        // 'middleware' => 'api',

        // The security scheme each guard stands for. None by default.
        // 'schemes' => ['sanctum' => 'bearerAuth'],

        // The guard a bare `auth` middleware uses. The application's `auth.defaults.guard` by
        // default, `web` where it sets none.
        // 'defaultGuard' => 'web',

        // Whether a route's name becomes its operationId.
        // 'nameAsOperationId' => false,

        // The path a server URL carries, taken off each route path that starts with it. None by default.
        // 'prefix' => '/api',

        // The RouteSource class to read the routes and the default guard from, created with no
        // arguments. RouteSource itself by default, which reads the running application's.
        // 'routeSource' => Radebatz\OpenApi\Introspector\Routing\Laravel\RouteSource::class,
    ],
];
