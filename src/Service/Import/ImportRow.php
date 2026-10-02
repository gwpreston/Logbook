<?php

declare(strict_types=1);

namespace Logbook\Service\Import;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * One CSV row of an import and what happens to it (spec.md §7.13).
 */
final readonly class ImportRow
{
    /**
     * @param int $line the row number a spreadsheet shows for it (the header is row 1)
     * @param array<string, string> $values field key → the text read for it
     * @param list<array{field: string, key: string, params: array<string, int|string|TranslatableInterface>}> $errors
     *        field key → translation key of the problem, for invalid rows
     * @param object|null $data what will be saved (the module's *Data object), for importable rows
     */
    public function __construct(
        public int $line,
        public ImportRowStatus $status,
        public array $values,
        public array $errors = [],
        public ?object $data = null,
        /**
         * What saving it will also do, for the preview (Phase 30.1: "new
         * station: Tesco Antrim"): a translation key and its parameters.
         *
         * @var array{key: string, params: array<string, string>}|null
         */
        public ?array $note = null,
    ) {
    }

    /**
     * The row at a glance: its first few non-empty values.
     */
    public function summary(int $fields = 4): string
    {
        $filled = array_values(array_filter($this->values, static fn (string $v): bool => $v !== ''));

        return implode(' · ', array_slice($filled, 0, $fields));
    }
}
