<?php

declare(strict_types=1);

namespace Logbook\Service\Import;

/**
 * Every row of an import with its outcome: the dry-run preview, and after
 * importing, the result (spec.md §7.13).
 */
final readonly class ImportPreview
{
    /**
     * @param list<ImportRow> $rows
     */
    public function __construct(
        public array $rows,
        /** After importing fill-ups: how many of them the economy check flags (spec.md §7.13). */
        public int $unusualFills = 0,
    ) {
    }

    public function count(ImportRowStatus $status): int
    {
        return count($this->withStatus($status));
    }

    /**
     * @return list<ImportRow>
     */
    public function withStatus(ImportRowStatus $status): array
    {
        return array_values(array_filter($this->rows, static fn (ImportRow $r): bool => $r->status === $status));
    }

    /**
     * Rows that will not be imported, in file order.
     *
     * @return list<ImportRow>
     */
    public function skipped(): array
    {
        return array_values(array_filter($this->rows, static fn (ImportRow $r): bool => $r->status !== ImportRowStatus::Import));
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
