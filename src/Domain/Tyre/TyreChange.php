<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

use DateTimeImmutable;
use Logbook\Support\Number\Decimal;

/**
 * One visit that touched a vehicle's tyres (spec.md §6 TyreChange): one
 * change, one odometer, one line per tyre. It has no cost of its own.
 */
final readonly class TyreChange
{
    /**
     * @param list<TyreChangeLine> $lines
     */
    public function __construct(
        public int $id,
        public int $vehicleId,
        public TyreChangeKind $kind,
        public TyreChangeData $data,
        public array $lines,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** Who added it (Phase 19); null = the vehicle's owner, or a former user. */
        public ?int $createdBy = null,
        /** The incident it is part of (Phase 27.1, spec.md §7.29). */
        public ?int $incidentId = null,
    ) {
    }

    /**
     * Replay order: by date, then odometer, then id (spec.md §7.17). A
     * change with no odometer (a repair) sorts after the others of its day,
     * so tyres fitted that day can be repaired that day; among themselves
     * such changes go by id.
     */
    public static function compare(self $a, self $b): int
    {
        return ($a->data->doneOn <=> $b->data->doneOn)
            ?: self::compareKm($a->data->odometerKm, $b->data->odometerKm)
            ?: $a->id <=> $b->id;
    }

    /**
     * @return list<int>
     */
    public function tyreIds(): array
    {
        return array_map(static fn (TyreChangeLine $line): int => $line->tyreId, $this->lines);
    }

    /**
     * The lines of one action.
     *
     * @return list<TyreChangeLine>
     */
    public function linesOf(TyreLineAction $action): array
    {
        return array_values(array_filter($this->lines, static fn (TyreChangeLine $l): bool => $l->action === $action));
    }

    public function withData(TyreChangeData $data): self
    {
        return new self($this->id, $this->vehicleId, $this->kind, $data, $this->lines, $this->createdAt, $this->updatedAt);
    }

    /**
     * @param list<TyreChangeLine> $lines
     */
    public function withLines(array $lines): self
    {
        return new self($this->id, $this->vehicleId, $this->kind, $this->data, $lines, $this->createdAt, $this->updatedAt);
    }

    private static function compareKm(?string $a, ?string $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null) <=> ($b === null);
        }

        return Decimal::compare($a, $b);
    }
}
