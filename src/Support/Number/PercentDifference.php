<?php

declare(strict_types=1);

namespace Logbook\Support\Number;

/**
 * A ratio worded as "about N% more / less", or "about the same" when it is
 * within SAME_WITHIN of 1 either way (spec.md §7.3, *Fuel insights*). The
 * band is tested on the exact ratio, then the percentage is rounded, so a
 * ratio that reads "the same" never shows as "0% more".
 */
final readonly class PercentDifference
{
    /** Under 1% either way reads "about the same". */
    public const string SAME_WITHIN = '0.01';

    private function __construct(
        /** 'more', 'less' or 'same' (an ICU select key). */
        public string $direction,
        /** Whole percent, 0 when the same. */
        public int $percent,
    ) {
    }

    /**
     * @param string $ratio canonical decimal, e.g. "1.104" (10% more)
     */
    public static function of(string $ratio): self
    {
        $difference = Decimal::subtract($ratio, '1');
        $magnitude = str_starts_with($difference, '-') ? substr($difference, 1) : $difference;
        if (Decimal::compare($magnitude, self::SAME_WITHIN) < 0) {
            return new self('same', 0);
        }

        $percent = (int) Decimal::round(Decimal::multiply($magnitude, '100', 6), 0);

        return new self(str_starts_with($difference, '-') ? 'less' : 'more', max(1, $percent));
    }

    /**
     * The percentage as a fraction, for ICU's `{x, number, percent}`.
     */
    public function fraction(): float
    {
        return $this->percent / 100;
    }
}
