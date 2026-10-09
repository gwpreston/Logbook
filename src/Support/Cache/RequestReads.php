<?php

declare(strict_types=1);

namespace Logbook\Support\Cache;

/**
 * What the repositories have read during the current page request, so a
 * table is read once however many widgets and services ask for it (spec.md
 * §8 *Page budgets*). Only a GET or HEAD request switches it on
 * (RequestReadsMiddleware): outside one (a job, a command, a POST) every
 * read goes to the database, as before. A write forgets what came from its
 * table (ForgetWrittenReadsMiddleware).
 *
 * Values are kept as loaded, so they must be immutable (the domain entities
 * are readonly) or lists of them.
 */
final class RequestReads
{
    private bool $active = false;

    /** @var array<string, array<int|string, mixed>> */
    private array $store = [];

    private bool $enabled = true;

    public function begin(): void
    {
        $this->active = $this->enabled;
        $this->store = [];
    }

    /**
     * Never remember anything again (a test compares a page with and
     * without; the app never calls this).
     */
    public function switchOff(): void
    {
        $this->enabled = false;
        $this->end();
    }

    public function end(): void
    {
        $this->active = false;
        $this->store = [];
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * The value for $key in $group: read it with $load the first time.
     *
     * @template T
     * @param callable(): T $load
     * @return T
     */
    public function remember(string $group, int|string $key, callable $load): mixed
    {
        if (!$this->active) {
            return $load();
        }

        if (!array_key_exists($key, $this->store[$group] ?? [])) {
            $this->store[$group][$key] = $load();
        }

        return $this->store[$group][$key];
    }

    /**
     * Read every id in $ids that $group doesn't have yet with one call to
     * $load, which returns the values keyed by id; an id it leaves out gets
     * $empty. Does nothing outside a request.
     *
     * @param list<int> $ids
     * @param callable(list<int>): array<int, mixed> $load
     */
    public function prime(string $group, array $ids, callable $load, mixed $empty): void
    {
        if (!$this->active) {
            return;
        }

        $missing = [];
        foreach (array_unique($ids) as $id) {
            if (!array_key_exists($id, $this->store[$group] ?? [])) {
                $missing[] = $id;
            }
        }
        if ($missing === []) {
            return;
        }

        $loaded = $load($missing);
        foreach ($missing as $id) {
            $this->store[$group][$id] = $loaded[$id] ?? $empty;
        }
    }

    /**
     * Items in the order given, grouped by the id $idOf names for each.
     *
     * @template T
     * @param list<T> $items
     * @param callable(T): int $idOf
     * @return array<int, list<T>>
     */
    public static function groupBy(array $items, callable $idOf): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $grouped[$idOf($item)][] = $item;
        }

        return $grouped;
    }

    /**
     * Drop every group that was read from $table, which was just written. A
     * group is named for its table, or `table+other` when its query joins
     * another (ForgetWrittenReadsMiddleware calls this).
     */
    public function forget(string $table): void
    {
        foreach (array_keys($this->store) as $group) {
            if (in_array($table, explode('+', $group), true)) {
                unset($this->store[$group]);
            }
        }
    }

    public function forgetAll(): void
    {
        $this->store = [];
    }
}
