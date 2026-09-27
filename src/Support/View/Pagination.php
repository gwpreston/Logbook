<?php

declare(strict_types=1);

namespace Logbook\Support\View;

/**
 * Page N of a list, from the `?page=` query parameter. Out-of-range pages
 * are clamped rather than shown empty.
 */
final readonly class Pagination
{
    public const int PER_PAGE = 25;

    public int $page;

    public function __construct(int $page, public int $total, public int $perPage = self::PER_PAGE)
    {
        $this->page = max(1, min($page, $this->pages()));
    }

    /**
     * @param array<array-key, mixed> $query
     */
    public static function fromQuery(array $query, int $total, int $perPage = self::PER_PAGE): self
    {
        $page = $query['page'] ?? '1';
        $page = is_string($page) && preg_match('/^\d{1,6}$/', $page) === 1 ? (int) $page : 1;

        return new self($page, $total, $perPage);
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages();
    }

    public function first(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function last(): int
    {
        return min($this->page * $this->perPage, $this->total);
    }

    /**
     * @template T
     * @param list<T> $items the whole list
     * @return list<T> this page's part of it
     */
    public function slice(array $items): array
    {
        return array_slice($items, ($this->page - 1) * $this->perPage, $this->perPage);
    }
}
