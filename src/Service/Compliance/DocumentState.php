<?php

declare(strict_types=1);

namespace Logbook\Service\Compliance;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Support\Date\LocalTime;

/**
 * A compliance document with its status on a given day.
 *
 * Of several documents of one type (last year's and this year's insurance),
 * the one that runs latest is current and the others are "replaced", so an
 * old policy never shows as expired once it has been renewed. A document
 * without an expiry counts as running latest. "Other" documents are
 * unrelated to each other and never replace one another.
 */
final readonly class DocumentState
{
    /** Expiring within this many days counts as "expiring" (lead times arrive in Phase 4). */
    public const int SOON_DAYS = 30;

    public function __construct(
        public ComplianceDocument $document,
        public DocumentStatus $status,
        /** Days from today to the expiry date; negative once expired. */
        public ?int $daysLeft,
    ) {
    }

    /**
     * @param list<ComplianceDocument> $documents one vehicle's documents
     * @param DateTimeImmutable $today calendar date (see LocalTime::today())
     * @return list<DocumentState> most urgent first, then by expiry
     */
    public static function evaluateAll(array $documents, DateTimeImmutable $today): array
    {
        $current = [];
        foreach ($documents as $document) {
            $type = $document->data->type;
            if (!$type->isSuperseded()) {
                continue;
            }
            $held = $current[$type->value] ?? null;
            if ($held === null || self::runsLater($document, $held)) {
                $current[$type->value] = $document;
            }
        }

        $states = [];
        foreach ($documents as $document) {
            $type = $document->data->type;
            $replaced = $type->isSuperseded() && ($current[$type->value] ?? null) !== $document;
            $states[] = self::evaluate($document, $today, $replaced);
        }

        usort($states, static fn (self $a, self $b): int => ($a->status->urgency() <=> $b->status->urgency())
            ?: self::compareExpiry($a->document, $b->document)
            ?: $a->document->id <=> $b->document->id);

        return $states;
    }

    public static function evaluate(ComplianceDocument $document, DateTimeImmutable $today, bool $replaced = false): self
    {
        $expiry = $document->data->expiryOn;
        $start = $document->data->startOn;
        $daysLeft = $expiry === null ? null : LocalTime::daysBetween($today, $expiry);

        $status = match (true) {
            $replaced => DocumentStatus::Replaced,
            $start !== null && $start > $today => DocumentStatus::Upcoming,
            $daysLeft === null => DocumentStatus::Open,
            $daysLeft < 0 => DocumentStatus::Expired,
            $daysLeft <= self::SOON_DAYS => DocumentStatus::Expiring,
            default => DocumentStatus::Valid,
        };

        return new self($document, $status, $daysLeft);
    }

    private static function runsLater(ComplianceDocument $a, ComplianceDocument $b): bool
    {
        $byExpiry = self::compareExpiry($a, $b);
        if ($byExpiry !== 0) {
            return $byExpiry > 0;
        }
        $byStart = $a->data->startOn <=> $b->data->startOn;

        return $byStart !== 0 ? $byStart > 0 : $a->id > $b->id;
    }

    /**
     * By expiry date, no expiry last.
     */
    private static function compareExpiry(ComplianceDocument $a, ComplianceDocument $b): int
    {
        $x = $a->data->expiryOn;
        $y = $b->data->expiryOn;

        return match (true) {
            $x === null && $y === null => 0,
            $x === null => 1,
            $y === null => -1,
            default => $x <=> $y,
        };
    }
}
