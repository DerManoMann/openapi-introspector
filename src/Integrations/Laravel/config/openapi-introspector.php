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

    // The LaravelAdapter settings, the keys L5-Swagger takes for it under `plugins`, such as
    // 'prefix' => '/api'.
    'adapter' => [],
];
