<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Logbook\Domain\Fuel\FuelEntry;

/**
 * A fill-up whose price is far from nearby ones (PriceOutlier).
 */
final readonly class PriceFinding
{
    public function __construct(
        public FuelEntry $entry,
        /** Median price per litre (kWh) of the fill-ups it was compared with. */
        public string $median,
        /** Its price ÷ the median, 6 places. */
        public string $ratio,
        /** Within ×/÷ 1.25 of a power of ten: "an extra or missing digit?". */
        public bool $digitSlip,
        /** Compared with the owner's other vehicles too (too few of this one's nearby). */
        public bool $wider = false,
    ) {
    }
}
