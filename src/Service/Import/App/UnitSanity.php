<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

use DateTimeImmutable;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Service\Fuel\FuelEconomy;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\ConsumptionUnit;

/**
 * The units' sanity line (spec.md §7.13 *Map*): the economy the file's
 * fill-ups give under the chosen units, by Logbook's own full-to-full rule,
 * compared with the app's own figure for each tank where the export has
 * one. Miles read as kilometres, or gallons as litres, show up here before
 * anything is written.
 *
 * Fuelio puts its figure on the fill-up that opens a segment; Logbook
 * measures at the one that closes it, so each segment is paired through its
 * opening fill-up.
 */
final readonly class UnitSanity
{
    /** A tank agrees with the app's figure within this fraction. */
    public const float TOLERANCE = 0.05;

    /** Plausible canonical consumption per kind: L, kWh or kg per 100 km. */
    private const array BANDS = [
        'liquid' => [1.0, 40.0],
        'electric' => [5.0, 40.0],
        'gas' => [1.0, 20.0],
    ];

    private function __construct(
        public EnergyKind $kind,
        /** Kilometres and volume over the measured tanks, canonical. */
        public string $distanceKm,
        public string $volume,
        public int $tanks,
        /** The app's average over the tanks it has a figure for, in L (kWh, kg) per 100 km. */
        public ?float $appPer100Km,
        /** The unit the app's figures were read in. */
        public ?ConsumptionUnit $appUnit,
        public int $compared,
        public int $agreeing,
    ) {
    }

    /**
     * @param list<FuelEntryData> $fills the importable fill-ups, in canonical units
     * @param list<string> $ownFigures the app's figure on each fill-up ('' when none), same order
     * @param string|null $appUnitText the app's consumption unit ("mpg")
     */
    public static function of(array $fills, array $ownFigures, ?string $appUnitText): ?self
    {
        $entries = [];
        $figures = [];
        $epoch = new DateTimeImmutable('@0');
        foreach ($fills as $i => $data) {
            $entries[] = new FuelEntry($i + 1, 0, $data, $epoch, $epoch);
            $figures[$i + 1] = $ownFigures[$i] ?? '';
        }
        // In the order they happened (an app may list the newest first).
        usort($entries, static fn (FuelEntry $a, FuelEntry $b): int => [$a->data->filledAt, (float) $a->data->odometerKm]
            <=> [$b->data->filledAt, (float) $b->data->odometerKm]);
        $history = FuelEconomy::analyse($entries);

        // The kind with the most measured tanks (petrol on a bi-fuel car that is mostly petrol).
        $kind = null;
        $measured = [];
        foreach (EnergyKind::cases() as $candidate) {
            $tanks = array_values(array_filter(
                $history->measured($candidate),
                static fn ($fill): bool => $fill->segment !== null,
            ));
            if (count($tanks) > count($measured)) {
                $kind = $candidate;
                $measured = $tanks;
            }
        }
        if ($kind === null || $measured === []) {
            return null;
        }

        $distance = '0';
        $volume = '0';
        $pairs = [];
        foreach ($measured as $fill) {
            $segment = $fill->segment;
            assert($segment !== null);
            $distance = Decimal::add($distance, $segment->distanceKm);
            $volume = Decimal::add($volume, $segment->volume);
            $own = $segment->opening === null ? '' : ($figures[$segment->opening->id] ?? '');
            if (is_numeric($own) && (float) $own > 0.0 && (float) $segment->distanceKm > 0.0) {
                $pairs[] = [
                    'km' => (float) $segment->distanceKm,
                    'logbook' => 100.0 * (float) $segment->volume / (float) $segment->distanceKm,
                    'app' => (float) $own,
                ];
            }
        }

        [$unit, $agreeing, $appPer100] = $kind === EnergyKind::Liquid ? self::compare($pairs, $appUnitText) : [null, 0, null];

        return new self(
            $kind,
            $distance,
            $volume,
            count($measured),
            $appPer100,
            $unit,
            $unit === null ? 0 : count($pairs),
            $agreeing,
        );
    }

    /**
     * Logbook's canonical average (L, kWh or kg per 100 km).
     */
    public function per100Km(): float
    {
        return (float) $this->distanceKm > 0.0 ? 100.0 * (float) $this->volume / (float) $this->distanceKm : 0.0;
    }

    /**
     * Outside what any car or bike plausibly does: highlighted.
     */
    public function isUnusual(): bool
    {
        [$low, $high] = self::BANDS[$this->kind->value];
        $value = $this->per100Km();

        return $value < $low || $value > $high;
    }

    /**
     * Whether Logbook's tanks agree with the app's own figures; null when
     * there was nothing to compare.
     */
    public function matchesApp(): ?bool
    {
        if ($this->compared === 0) {
            return null;
        }

        return $this->agreeing >= (int) ceil(0.9 * $this->compared);
    }

    /**
     * Read the app's figures in the unit its header names; for "mpg", the
     * UK or US gallon that agrees with more tanks (Fuelio doesn't say which).
     *
     * @param list<array{km: float, logbook: float, app: float}> $pairs
     * @return array{0: ?ConsumptionUnit, 1: int, 2: ?float}
     */
    private static function compare(array $pairs, ?string $text): array
    {
        $candidates = match (strtolower(str_replace(' ', '', (string) $text))) {
            'mpg' => [ConsumptionUnit::MpgUk, ConsumptionUnit::MpgUs],
            'mpg(us)' => [ConsumptionUnit::MpgUs],
            'l/100km' => [ConsumptionUnit::LitresPer100Km],
            'km/l' => [ConsumptionUnit::KmPerLitre],
            default => [],
        };
        if ($pairs === [] || $candidates === []) {
            return [null, 0, null];
        }

        $best = null;
        foreach ($candidates as $unit) {
            $agreeing = 0;
            $weighted = 0.0;
            $km = 0.0;
            foreach ($pairs as $pair) {
                $app = $unit->toLitresPer100Km($pair['app']);
                if (abs($pair['logbook'] / $app - 1.0) <= self::TOLERANCE) {
                    $agreeing++;
                }
                $weighted += $app * $pair['km'];
                $km += $pair['km'];
            }
            if ($best === null || $agreeing > $best[1]) {
                $best = [$unit, $agreeing, $km > 0.0 ? $weighted / $km : null];
            }
        }

        return $best;
    }
}
