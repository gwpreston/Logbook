<?php

declare(strict_types=1);

namespace Logbook\Domain\MotHistory;

use DateTimeImmutable;

/**
 * A stored defect of a test (spec.md §6 MotDefect): the issue made from it
 * or updated by it, and *Not now* on the review card.
 */
final readonly class MotDefect
{
    public function __construct(
        public int $id,
        public int $motTestId,
        public int $position,
        public MotDefectType $type,
        public string $text,
        public bool $dangerous,
        public ?int $issueId,
        public ?DateTimeImmutable $dismissedAt,
    ) {
    }

    /** Taken (an issue) or put off (*Not now*): nothing left to offer. */
    public function settled(): bool
    {
        return $this->issueId !== null || $this->dismissedAt !== null;
    }

    /**
     * The text compared for a repeat (spec.md §7.38 *Repeats*): case-folded,
     * whitespace collapsed.
     */
    public function key(): string
    {
        return self::textKey($this->text);
    }

    /**
     * The same comparison for any text: an issue's, to find the defect it was made from.
     */
    public static function textKey(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
