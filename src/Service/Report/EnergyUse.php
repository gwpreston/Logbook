<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Support\Money\Money;

/**
 * What a vehicle spent on one kind of energy in a period and how much of it
 * it bought (spec.md §7.35 *What changed*): litres, kWh or kg.
 */
final readonly class EnergyUse
{
    public function __construct(
        public EnergyKind $kind,
        public Money $cost,
        /** Units bought (canonical decimal). */
        public string $units,
    ) {
    }
}
