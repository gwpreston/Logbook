<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use DateTimeImmutable;
use Logbook\Domain\Trip\TaxYear;
use Logbook\Support\Date\LocalTime;

/**
 * What a claim report covers (spec.md §7.23): a tax year (default the
 * current one) or a custom date range, and some or all vehicles. Read from
 * a plain GET form: `year`, or `from` and `to`, and `vehicles[]`.
 */
final readonly class ClaimFilter
{
    /**
     * @param list<int> $vehicleIds empty = every vehicle
     */
    public function __construct(
        /** First day, inclusive. */
        public DateTimeImmutable $from,
        /** Last day, inclusive. */
        public DateTimeImmutable $to,
        public ?TaxYear $taxYear,
        public array $vehicleIds = [],
    ) {
    }

    /**
     * @param list<int> $vehicleIds empty = every vehicle
     */
    public static function taxYear(TaxYear $year, array $vehicleIds = []): self
    {
        return new self($year->start, $year->lastDay(), $year, $vehicleIds);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    public static function fromQuery(array $query, string $taxYearStart, DateTimeImmutable $today): self
    {
        $vehicles = [];
        $asked = $query['vehicles'] ?? [];
        foreach (is_array($asked) ? $asked : [] as $id) {
            if (is_string($id) && ctype_digit($id) && (int) $id > 0) {
                $vehicles[] = (int) $id;
            }
        }

        $from = is_string($query['from'] ?? null) ? LocalTime::parseDate($query['from']) : null;
        $to = is_string($query['to'] ?? null) ? LocalTime::parseDate($query['to']) : null;
        if (($query['period'] ?? '') === 'custom' && $from !== null && $to !== null && $from <= $to) {
            return new self($from, $to, null, $vehicles);
        }

        $year = $query['year'] ?? null;
        $taxYear = is_string($year) && preg_match('/^\d{4}$/', $year) === 1
            ? TaxYear::startingIn((int) $year, $taxYearStart)
            : TaxYear::containing($today, $taxYearStart);

        return self::taxYear($taxYear, $vehicles);
    }

    public function isCustom(): bool
    {
        return $this->taxYear === null;
    }

    /**
     * The day after the last, for half-open queries.
     */
    public function until(): DateTimeImmutable
    {
        return $this->to->modify('+1 day');
    }

    public function includes(int $vehicleId): bool
    {
        return $this->vehicleIds === [] || in_array($vehicleId, $this->vehicleIds, true);
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function toQuery(): array
    {
        $query = $this->taxYear === null
            ? ['period' => 'custom', 'from' => $this->from->format('Y-m-d'), 'to' => $this->to->format('Y-m-d')]
            : ['year' => (string) $this->taxYear->startYear()];
        if ($this->vehicleIds !== []) {
            $query['vehicles'] = array_map('strval', $this->vehicleIds);
        }

        return $query;
    }

    /**
     * For file names: the tax year ("2026-27") or the range.
     */
    public function slug(): string
    {
        return $this->taxYear?->slug() ?? $this->from->format('Y-m-d') . '-to-' . $this->to->format('Y-m-d');
    }
}
