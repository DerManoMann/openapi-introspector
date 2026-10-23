# Verifying responses

The document says what each route returns.
[openapi-verifier](https://github.com/DerManoMann/openapi-verifier) checks that it does: each
response a test or a PSR-15 stack sees is validated against the schema the document gives it.
It has adapters for Laravel and Slim tests, PSR-15 middleware and Guzzle or PSR-18 clients.

Pass it the document the introspector built. Without one, the verifier generates its own from
the attributes alone. A handler that carries only `#[OA\Response]` gets its path from the route,
so its responses are missing from that document and are not checked.

In a Laravel test, with the file the artisan command in
[Generating for L5-Swagger](l5-swagger.md) writes:

```php
use Radebatz\OpenApi\Verifier\Adapters\Laravel\OpenApiResponseVerifier;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use OpenApiResponseVerifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOpenApiVerifier(null, storage_path('api-docs/api-docs.json'));
    }
}
```

Generate the file before the tests run. Only responses the document has a schema for are
verified, so a route nothing but the router describes is skipped.
