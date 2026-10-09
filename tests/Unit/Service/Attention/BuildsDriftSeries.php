<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Attention;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Service\Attention\DriftFinding;
use Logbook\Service\Attention\EconomyDrift;
use Logbook\Service\Fuel\FuelEconomy;
use Logbook\Tests\Unit\Service\Fuel\BuildsFills;

/**
 * Series of fill-ups for the drift test (spec.md §7.24 item 7) and its
 * improvement, *Economy up* (§7.8): each segment 600 km unless asked, a
 * figure in litres (kWh) per 100 km. "Now" is 1 Oct 2026, London.
 */
trait BuildsDriftSeries
{
    use BuildsFills;

    private const string NOW = '2026-10-01 12:00';

    private DateTimeZone $zone;
    /** @var array<string, string> odometer by fuel */
    private array $odometer = [];

    /**
     * @param list<FuelEntry> $fills
     * @param (\Closure(): list<DateTimeImmutable>)|null $fits
     * @param bool $both EconomyDrift::judge() (either outcome), not of() (worse only)
     */
    private function judge(
        array $fills,
        EnergyKind $kind = EnergyKind::Liquid,
        int $percent = 10,
        string $now = self::NOW,
        ?\Closure $fits = null,
        bool $overdue = false,
        bool $both = false,
    ): ?DriftFinding {
        usort($fills, static fn (FuelEntry $a, FuelEntry $b): int => $a->data->filledAt <=> $b->data->filledAt);

        return ($both ? EconomyDrift::judge(...) : EconomyDrift::of(...))(
            FuelEconomy::analyse($fills),
            $kind,
            $percent,
            new DateTimeImmutable($now, $this->zone),
            $this->zone,
            $fits,
            $overdue,
        );
    }

    /**
     * An opening full fill, then $baseline segments at $before and
     * $recentCount at $after, every $baselineEvery / $recentEvery days.
     *
     * @return list<FuelEntry>
     */
    private function series(
        int $baseline,
        string $before,
        string $after,
        int $recentCount = 5,
        string $recentFrom = '2026-07-01',
        string $baselineFrom = '2025-11-01',
        int $recentEvery = 18,
        int $baselineEvery = 28,
        int $recentKm = 600,
        ?FuelGrade $baselineGrade = null,
        ?FuelGrade $recentGrade = null,
        Fuel $fuel = Fuel::Petrol,
    ): array {
        $key = $fuel->value;
        // One odometer: a plug-in hybrid's series share it.
        $this->odometer[$key] = '10000';
        $start = new DateTimeImmutable($baselineFrom . ' 09:00', $this->zone);
        $fills = [$this->fill($start->modify('-' . $baselineEvery . ' days'), 0, '40', $fuel, $baselineGrade)];
        for ($i = 0; $i < $baseline; $i++) {
            $fills[] = $this->fill(
                $start->modify('+' . ($i * $baselineEvery) . ' days'),
                600,
                self::litres($before, 600),
                $fuel,
                $baselineGrade,
            );
        }
        $recent = new DateTimeImmutable($recentFrom . ' 09:00', $this->zone);
        for ($i = 0; $i < $recentCount; $i++) {
            $fills[] = $this->fill(
                $recent->modify('+' . ($i * $recentEvery) . ' days'),
                $recentKm,
                self::litres($after, $recentKm),
                $fuel,
                $recentGrade,
            );
        }
        if ($recentGrade !== null && $baseline > 0) {
            // A segment burns its opening fill's grade: the first recent
            // segment opens on the last baseline fill, so that one is the
            // recent grade too.
            $last = $fills[$baseline];
            $fills[$baseline] = $this->fill(
                $last->data->filledAt,
                0,
                $last->data->volume,
                $fuel,
                $recentGrade,
                $last->data->odometerKm,
            );
        }

        return array_values($fills);
    }

    /**
     * @param list<FuelEntry> $fills
     * @param list<string> $dates
     * @return list<FuelEntry>
     */
    private function append(array $fills, array $dates, string $figure, Fuel $fuel = Fuel::Petrol): array
    {
        foreach ($dates as $date) {
            $fills[] = $this->fill(
                new DateTimeImmutable($date . ' 09:00', $this->zone),
                600,
                self::litres($figure, 600),
                $fuel,
                null,
            );
        }

        return $fills;
    }

    /**
     * Replaces the second baseline segment with a 90 km one.
     *
     * @param list<FuelEntry> $fills
     * @return list<FuelEntry>
     */
    private function insertShort(array $fills): array
    {
        $at = $fills[1]->data->filledAt->modify('+2 days');
        $fills[] = $this->fill($at, 0, '4.5', Fuel::Petrol, null, (string) ((int) $fills[1]->data->odometerKm + 90));
        $shift = [];
        foreach ($fills as $fill) {
            if ($fill->data->filledAt > $at) {
                $shift[] = $fill;
            }
        }
        // Push the later odometers on by 90 km so the rest stay 600 km.
        return array_map(
            fn (FuelEntry $f): FuelEntry => in_array($f, $shift, true)
                ? $this->fill(
                    $f->data->filledAt,
                    0,
                    $f->data->volume,
                    $f->data->fuel,
                    $f->data->grade,
                    (string) ((int) $f->data->odometerKm + 90),
                )
                : $f,
            $fills,
        );
    }

    private function fill(
        DateTimeImmutable $at,
        int $distance,
        string $volume,
        Fuel $fuel,
        ?FuelGrade $grade,
        ?string $odometer = null,
    ): FuelEntry {
        $key = $fuel->value;
        if ($odometer === null) {
            $this->odometer[$key] = (string) ((int) ($this->odometer[$key] ?? '10000') + $distance);
            $odometer = $this->odometer[$key];
        }

        return $this->fillAt(
            $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $odometer,
            $volume,
            $fuel === Fuel::Electricity ? '0.25' : '1.40',
            $grade,
            $fuel,
        );
    }

    private static function litres(string $per100, int $km): string
    {
        return number_format((float) $per100 * $km / 100, 3, '.', '');
    }
}
