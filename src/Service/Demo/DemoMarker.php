<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

use DateTimeImmutable;

/**
 * The proof that a database was seeded as a demo (spec.md §7.36): the
 * global setting `demo.instance`. Only the demo seeding path writes it, and
 * it is never in a backup or an export.
 */
final readonly class DemoMarker
{
    public const string SETTING = 'demo.instance';

    public function __construct(
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastResetAt,
        /** What wrote it: `DEMO_MODE` (the start path) or `bin/demo-reset.php`. */
        public string $seededBy,
    ) {
    }

    public function resetAt(DateTimeImmutable $at): self
    {
        return new self($this->createdAt, $at, $this->seededBy);
    }

    /**
     * @return array{created_at: string, last_reset_at: string, seeded_by: string}
     */
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'last_reset_at' => $this->lastResetAt->format(DATE_ATOM),
            'seeded_by' => $this->seededBy,
        ];
    }

    public static function fromValue(mixed $value): ?self
    {
        if (!is_array($value) || !is_string($value['created_at'] ?? null) || !is_string($value['last_reset_at'] ?? null)) {
            return null;
        }

        try {
            return new self(
                new DateTimeImmutable($value['created_at']),
                new DateTimeImmutable($value['last_reset_at']),
                is_string($value['seeded_by'] ?? null) ? $value['seeded_by'] : 'DEMO_MODE',
            );
        } catch (\Exception) {
            return null;
        }
    }
}
