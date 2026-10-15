<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use DateTimeImmutable;
use Logbook\Domain\Part\PartFitting;
use Logbook\Support\Number\Decimal;

/**
 * A part's distance on the vehicle (spec.md §7.39 *Distance*): derived on
 * every read, never stored. Each fitting's span is its removed mileage (or,
 * while fitted, the vehicle's latest reading) less its fitted mileage. A
 * span without both ends counts nothing and the total is "at least"; a
 * negative span counts 0 and is flagged, as for tyres.
 */
final readonly class PartDistance
{
    public function __construct(
        /** Kilometres; null when no span could be measured. */
        public ?string $km,
        /** Some span could not be measured, so the true figure is at least this. */
        public bool $atLeast,
        /** The first span that runs backwards, for the flag. */
        public ?DateTimeImmutable $backwardsFrom = null,
        public ?DateTimeImmutable $backwardsTo = null,
    ) {
    }

    /**
     * @param list<PartFitting> $fittings oldest first
     * @param string|null $currentKm the vehicle's latest reading
     * @param DateTimeImmutable $today the owner's calendar date, the end of an open span
     */
    public static function of(array $fittings, ?string $currentKm, DateTimeImmutable $today): self
    {
        $km = null;
        $atLeast = false;
        $from = null;
        $to = null;
        foreach ($fittings as $fitting) {
            $end = $fitting->isOpen() ? $currentKm : $fitting->removedOdometerKm;
            if ($fitting->fittedOdometerKm === null || $end === null) {
                $atLeast = true;
                continue;
            }
            $span = Decimal::subtract($end, $fitting->fittedOdometerKm);
            if (Decimal::compare($span, '0') < 0) {
                if ($from === null) {
                    $from = $fitting->fittedOn;
                    $to = $fitting->removedOn ?? $today;
                }
                $span = '0';
            }
            $km = Decimal::add($km ?? '0', $span);
        }

        return new self($km === null ? null : Decimal::round($km, 3), $atLeast && $km !== null, $from, $to);
    }

    public function isFlagged(): bool
    {
        return $this->backwardsFrom !== null;
    }
}
