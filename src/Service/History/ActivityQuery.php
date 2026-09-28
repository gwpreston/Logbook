<?php

declare(strict_types=1);

namespace Logbook\Service\History;

use DateTimeImmutable;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Date\LocalTime;

/**
 * What to read from the activity feed (spec.md §7.16): the vehicles, the
 * kinds, a range of the owner's calendar dates and at most how many lines.
 * Without a range every line is read (the print view).
 */
final readonly class ActivityQuery
{
    /**
     * @param list<Vehicle> $vehicles already resolved for the owner
     * @param list<ActivityKind> $kinds
     * @param DateTimeImmutable|null $from first calendar day, inclusive
     * @param DateTimeImmutable|null $until calendar day after the last, exclusive
     */
    public function __construct(
        public array $vehicles,
        public array $kinds,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $until = null,
        public ?int $limit = null,
    ) {
    }

    /**
     * One calendar year in the owner's time zone.
     *
     * @param list<Vehicle> $vehicles
     * @param list<ActivityKind> $kinds
     */
    public static function year(array $vehicles, array $kinds, int $year): self
    {
        return new self($vehicles, $kinds, self::newYear($year), self::newYear($year + 1));
    }

    public static function newYear(int $year): DateTimeImmutable
    {
        $day = LocalTime::parseDate(sprintf('%04d-01-01', $year));
        assert($day !== null);

        return $day;
    }

    public function includes(ActivityKind $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }

    /**
     * @return list<int>
     */
    public function vehicleIds(): array
    {
        return array_map(static fn (Vehicle $v): int => $v->id, $this->vehicles);
    }

    public function covers(DateTimeImmutable $date): bool
    {
        return ($this->from === null || $date >= $this->from) && ($this->until === null || $date < $this->until);
    }
}
