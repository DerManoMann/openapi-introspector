<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Schemas\LaravelData;

use OpenApi\Augmenter\Group;
use OpenApi\Spec as OA;
use OpenApi\Specification;
use OpenApi\Utils\PipeInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Spatie\LaravelData\Contracts\BaseData;

/**
 * Gives an operation the data objects its handler takes and returns.
 *
 * A handler parameter typed with a data class is the request body, since laravel-data fills it
 * from the request and validates it first, so the operation also answers 422. A data class as the
 * declared return type is the 200 response. What the operation has already is kept: a request
 * body, or a 2xx response, from attributes or another source stays as it is, and the 422 is added
 * either way.
 *
 * Where the class's request and response differ, the request body references its input schema.
 *
 * It runs as an augmenter at the start of the resolve phase, after `IntrospectorPlugin` has added
 * the operations it reads from routes, and before swagger-php reads types and rewrites references.
 * Augmenters do not ask the resolvers, so it adds the components it references itself, through
 * `DataResolver`, together with the data classes they hold.
 *
 * @implements PipeInterface<Specification>
 */
class DataOperations implements PipeInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var list<array{class-string, bool}> the components the operations reference, as class and input */
    protected array $referenced = [];

    public function __construct(protected DataResolver $resolver = new DataResolver())
    {
    }

    public function group(): Group
    {
        return Group::Resolve;
    }

    public function __invoke(mixed $specification): Specification
    {
        $this->referenced = [];
        foreach ($specification->operations as $operation) {
            $reflector = $operation->getReflector();
            if (!$reflector instanceof \ReflectionMethod) {
                continue;
            }

            if (null !== ($class = $this->inputClass($reflector))) {
                // laravel-data validates the object whatever describes the body
                $this->addResponse($operation, new OA\Response(response: 422, description: 'Unprocessable Content'));
                if ($operation->requestBody === null) {
                    $input = DataResolver::differs($class);
                    $operation->requestBody = new OA\RequestBody(
                        required: true,
                        content: new OA\Schema(ref: $input ? new OA\Schema\Ref(ref: $class, qualifiers: ['direction' => 'input']) : $class),
                    );
                    $this->referenced[] = [$class, $input];
                }
            }

            if (null !== ($class = $this->dataClass($reflector->getReturnType())) && !$this->answersSuccess($operation)) {
                $this->addResponse($operation, new OA\Response(response: 200, description: 'OK', content: new OA\Schema(ref: $class)));
                $this->referenced[] = [$class, false];
            }
        }

        $this->addComponents($specification);

        return $specification;
    }

    /**
     * Adds a component for each referenced data class, and for the data classes those hold, that
     * the specification does not have yet.
     */
    protected function addComponents(Specification $specification): void
    {
        $this->resolver->setLogger($this->logger ?? new NullLogger());
        $index = $specification->buildComponentIndex();
        $queue = $this->referenced;
        $seen = [];
        while ([] !== $queue) {
            [$class, $input] = array_shift($queue);
            $key = DataResolver::key($class, $input);
            if (isset($seen[(string) $key])) {
                continue;
            }
            $seen[(string) $key] = true;

            if (!$index->hasKey($key) && ($schema = $this->resolver->describe($class, $input)) instanceof OA\Schema) {
                $specification->add($schema);
            }

            foreach (DataResolver::nested($class) as $nested) {
                $queue[] = [$nested, false];
            }
        }
    }

    /**
     * @return class-string|null
     */
    protected function inputClass(\ReflectionMethod $reflector): ?string
    {
        foreach ($reflector->getParameters() as $parameter) {
            if (null !== ($class = $this->dataClass($parameter->getType()))) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @return class-string|null
     */
    protected function dataClass(?\ReflectionType $type): ?string
    {
        return $type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_a($type->getName(), BaseData::class, true) ? $type->getName() : null;
    }

    protected function answersSuccess(OA\Operation $operation): bool
    {
        foreach ($operation->responses ?? [] as $response) {
            if (str_starts_with((string) $response->response, '2')) {
                return true;
            }
        }

        return false;
    }

    protected function addResponse(OA\Operation $operation, OA\Response $response): void
    {
        foreach ($operation->responses ?? [] as $existing) {
            if ((string) $existing->response === (string) $response->response) {
                return;
            }
        }

        $responses = [...$operation->responses ?? [], $response];
        usort($responses, static fn (OA\Response $a, OA\Response $b): int => strcmp((string) $a->response, (string) $b->response));
        $operation->responses = $responses;
    }
}
