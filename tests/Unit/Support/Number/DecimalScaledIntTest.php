<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Number;

use Logbook\Support\Number\Decimal;
use OverflowException;
use PHPUnit\Framework\TestCase;

/**
 * Decimal::toScaledInt()'s quick path for values that need no rounding
 * (Phase 54.5, K149) gives what the full path gives, for any sign, places,
 * leading zeros and length up to what fits, and still refuses what doesn't.
 */
final class DecimalScaledIntTest extends TestCase
{
    public function testEveryValueScalesAsBefore(): void
    {
        mt_srand(54);
        $values = ['0', '-0', '0.000', '-0.000', '007', '-007.50', '0.5', '-0.5', '999999999999999999', '-99999999.9999999999'];
        for ($i = 0; $i < 3000; $i++) {
            $whole = (string) mt_rand(0, 10 ** mt_rand(0, 9));
            $places = mt_rand(0, 9);
            $fraction = $places === 0 ? '' : '.' . str_pad((string) mt_rand(0, 10 ** $places - 1), $places, '0', STR_PAD_LEFT);
            $values[] = (mt_rand(0, 1) === 1 ? '-' : '') . (mt_rand(0, 9) === 0 ? '0' : '') . $whole . $fraction;
        }

        foreach ($values as $value) {
            foreach ([0, 1, 2, 3, 6, 7, 9, 12] as $scale) {
                $expected = self::before($value, $scale);
                $actual = null;
                try {
                    $actual = Decimal::toScaledInt($value, $scale);
                } catch (OverflowException) {
                    $actual = 'overflow';
                }
                self::assertSame($expected, $actual, "$value at $scale");
            }
        }
    }

    /**
     * toScaledInt() as it was before Phase 54.5.
     */
    private static function before(string $value, int $scale): int|string
    {
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $whole = ltrim($whole, '0');
        $kept = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $roundUp = strlen($fraction) > $scale && (int) $fraction[$scale] >= 5;
        $digits = ltrim($whole . $kept, '0');
        if (strlen($digits) > 18) {
            return 'overflow';
        }
        $magnitude = (int) ($digits === '' ? '0' : $digits) + ($roundUp ? 1 : 0);

        return $negative ? -$magnitude : $magnitude;
    }
}
