<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use DateTimeImmutable;
use Logbook\Domain\Incident\Fault;
use Logbook\Support\Date\LocalTime;

/**
 * The claims history's filters (spec.md §7.29 *Claims history*): the last
 * 3, 5 (default) or 10 years, or a date range; a vehicle; a driver; claims
 * only or every incident; a fault.
 */
final readonly class ClaimsFilter
{
    public const array YEARS = [3, 5, 10];
    public const int DEFAULT_YEARS = 5;

    public function __construct(
        /** One of YEARS; ignored when a range is given. */
        public int $years = self::DEFAULT_YEARS,
        /** Calendar dates, both inclusive. */
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $until = null,
        public ?int $vehicleId = null,
        /** A user's id, or a typed driver's name ("name:Sam"). */
        public ?string $driver = null,
        public bool $claimsOnly = false,
        public ?Fault $fault = null,
    ) {
    }

    /**
     * The filter from the page's plain GET form; anything unreadable falls
     * back to its default.
     *
     * @param array<array-key, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $string = static fn (string $key): string => is_string($query[$key] ?? null) ? trim($query[$key]) : '';
        $years = (int) $string('years');
        $from = $string('from') === '' ? null : LocalTime::parseDate($string('from'));
        $until = $string('to') === '' ? null : LocalTime::parseDate($string('to'));
        if ($from !== null && $until !== null && $from > $until) {
            [$from, $until] = [$until, $from];
        }
        $vehicle = $string('vehicle');
        $driver = $string('driver');

        return new self(
            years: in_array($years, self::YEARS, true) ? $years : self::DEFAULT_YEARS,
            from: $string('period') === 'range' ? $from : null,
            until: $string('period') === 'range' ? $until : null,
            vehicleId: ctype_digit($vehicle) ? (int) $vehicle : null,
            driver: $driver === '' ? null : $driver,
            claimsOnly: $string('claims') === '1',
            fault: Fault::tryFrom($string('fault')),
        );
    }

    /**
     * The query that gives this filter back (for the CSV link).
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'years' => $this->isRange() ? '' : (string) $this->years,
            'period' => $this->isRange() ? 'range' : '',
            'from' => $this->from?->format('Y-m-d') ?? '',
            'to' => $this->until?->format('Y-m-d') ?? '',
            'vehicle' => $this->vehicleId === null ? '' : (string) $this->vehicleId,
            'driver' => $this->driver ?? '',
            'claims' => $this->claimsOnly ? '1' : '',
            'fault' => $this->fault->value ?? '',
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * The first day counted: the range's start, else the same day $years
     * before today, by calendar date.
     */
    public function start(DateTimeImmutable $today): ?DateTimeImmutable
    {
        if ($this->from !== null || $this->until !== null) {
            return $this->from;
        }

        return $today->modify(sprintf('-%d years', $this->years));
    }

    public function end(DateTimeImmutable $today): DateTimeImmutable
    {
        return $this->from !== null || $this->until !== null ? ($this->until ?? $today) : $today;
    }

    public function isRange(): bool
    {
        return $this->from !== null || $this->until !== null;
    }
}
