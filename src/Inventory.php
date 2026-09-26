<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector;

use Radebatz\OpenApi\Introspector\Inventory\Entry;

/**
 * Every operation the application serves or describes, and which it is.
 *
 * Built while the adapters run rather than read off the finished document, because there an
 * operation the framework routes and one only attributes describe look the same.
 */
final class Inventory
{
    /** @var array<string, Entry> */
    private array $entries = [];

    /**
     * Records an entry; the first entry for a method and path is kept and later ones ignored.
     */
    public function record(Entry $entry): void
    {
        $this->entries[$entry->key()] ??= $entry;
    }

    public function has(string $method, string $path): bool
    {
        return isset($this->entries[Entry::keyOf($method, $path)]);
    }

    public function get(string $method, string $path): ?Entry
    {
        return $this->entries[Entry::keyOf($method, $path)] ?? null;
    }

    /**
     * @return list<Entry>
     */
    public function entries(?Status $status = null): array
    {
        $entries = array_values($this->entries);

        return $status instanceof Status
            ? array_values(array_filter($entries, static fn (Entry $entry): bool => $entry->status === $status))
            : $entries;
    }

    /**
     * @return list<array{method: string, path: string, status: string, adapter: string|null}>
     */
    public function toArray(): array
    {
        return array_map(static fn (Entry $entry): array => $entry->toArray(), $this->entries());
    }
}
