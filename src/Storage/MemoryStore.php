<?php

declare(strict_types=1);

namespace EidCloud\ApiMock\Storage;

/**
 * In-memory stateful mock data store supporting CRUD operations on simulated resources.
 */
class MemoryStore
{
    private array $data = [];

    public function __construct(array $initialData = [])
    {
        $this->data = $initialData;
    }

    /**
     * Resets or clears the store.
     */
    public function clear(): void
    {
        $this->data = [];
    }

    /**
     * Extracts collection name from path e.g. /v1/users/{id} => 'users'
     */
    public static function extractCollectionName(string $path): string
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        if (empty($parts)) {
            return 'root';
        }

        // If last element is parameter like {id}, use preceding element
        $last = end($parts);
        if ((str_starts_with($last, '{') && str_ends_with($last, '}')) || str_starts_with($last, ':')) {
            if (count($parts) >= 2) {
                return $parts[count($parts) - 2];
            }
            return 'items';
        }

        return $last;
    }

    /**
     * Returns all records in a collection.
     */
    public function all(string $collection): array
    {
        return array_values($this->data[$collection] ?? []);
    }

    /**
     * Finds an item in a collection by primary key or ID.
     */
    public function find(string $collection, string|int $id): ?array
    {
        return $this->data[$collection][(string) $id] ?? null;
    }

    /**
     * Adds or updates an item in the collection.
     */
    public function set(string $collection, string|int $id, array $item): void
    {
        if (!isset($this->data[$collection])) {
            $this->data[$collection] = [];
        }
        $this->data[$collection][(string) $id] = $item;
    }

    /**
     * Creates and stores a new entity, assigning an ID if missing.
     */
    public function create(string $collection, array $payload): array
    {
        if (!isset($payload['id'])) {
            $existingKeys = array_keys($this->data[$collection] ?? []);
            $numericKeys = array_filter($existingKeys, 'is_numeric');
            $nextId = empty($numericKeys) ? 1 : (max($numericKeys) + 1);
            $payload['id'] = $nextId;
        }

        $id = (string) $payload['id'];
        $this->data[$collection][$id] = $payload;

        return $payload;
    }

    /**
     * Updates an existing entity or creates it.
     */
    public function update(string $collection, string|int $id, array $payload): array
    {
        $existing = $this->find($collection, $id) ?? [];
        $payload['id'] = $id;
        $merged = array_merge($existing, $payload);
        $this->set($collection, $id, $merged);
        return $merged;
    }

    /**
     * Deletes an item from the collection.
     */
    public function delete(string $collection, string|int $id): bool
    {
        $idStr = (string) $id;
        if (isset($this->data[$collection][$idStr])) {
            unset($this->data[$collection][$idStr]);
            return true;
        }
        return false;
    }

    /**
     * Checks if a collection has items.
     */
    public function hasCollection(string $collection): bool
    {
        return !empty($this->data[$collection]);
    }
}
