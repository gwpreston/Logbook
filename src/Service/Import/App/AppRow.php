<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * One row of an app's export and what the import does with it.
 */
final readonly class AppRow
{
    /**
     * @param int $line the record's number in its file
     * @param string $sourceId the row's own id in the export (Fuelio's `guid`)
     * @param string $summary the row at a glance, for the preview
     * @param list<array{field: string, key: string, params: array<string, int|string|TranslatableInterface>}> $errors
     * @param object|null $data what will be saved, for importable rows
     * @param array{key: string, params: array<string, string>}|null $note what saving it also does, or why it is skipped
     * @param array<string, mixed> $extra the section's own details (a fill-up's photos, a photo's fill-up)
     */
    public function __construct(
        public int $line,
        public AppRowStatus $status,
        public string $sourceId,
        public string $summary,
        public array $errors = [],
        public ?object $data = null,
        public ?array $note = null,
        public array $extra = [],
    ) {
    }

    /**
     * @param array{key: string, params: array<string, string>}|null $note
     */
    public function withStatus(AppRowStatus $status, ?array $note = null): self
    {
        return new self(
            $this->line,
            $status,
            $this->sourceId,
            $this->summary,
            $this->errors,
            $this->data,
            $note ?? $this->note,
            $this->extra,
        );
    }
}
