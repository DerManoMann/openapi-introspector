<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Inventory;

use Radebatz\OpenApi\Introspector\Status;

/**
 * One operation, by method and path, and how it reached the document.
 */
final readonly class Entry
{
    /**
     * @param class-string|null $adapter the adapter that yielded the operation, if one did
     */
    public function __construct(
        public string $method,
        public string $path,
        public Status $status,
        public ?string $adapter = null,
    ) {
    }

    public static function keyOf(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $path;
    }

    public function key(): string
    {
        return self::keyOf($this->method, $this->path);
    }

    /**
     * @return array{method: string, path: string, status: string, adapter: string|null}
     */
    public function toArray(): array
    {
        return [
            'method' => strtoupper($this->method),
            'path' => $this->path,
            'status' => $this->status->value,
            'adapter' => $this->adapter,
        ];
    }
}
