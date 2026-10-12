<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Household;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeZone;
use Logbook\Tests\Support\Golden\ExpectedSkeleton;
use Logbook\Tests\Support\Golden\GoldenHousehold;

/**
 * What one person of the household (Household::PEOPLE) must not see,
 * taken from the golden inputs, for Phase 45.2's leak sweep:
 *
 * - **amounts** on vehicles whose costs they may not see, unless the entry
 *   is their own fill-up (they see the amounts they typed), and on vehicles
 *   they can't see at all;
 * - **vehicles** outside their shares, by registration (and the two model
 *   names no page uses for anything else);
 * - **places** from other drivers' trips they may not see.
 *
 * The golden amounts are distinctive, so each is an exact search, not a
 * guess. An amount is left out when the same value is one they may see,
 * or any distance, volume or price in the inputs, or under FLOOR, so a match
 * means the amount itself. Amounts are matched as numbers with two
 * decimals (or three, the third a zero), grouped or not: "£1,234.56" on a
 * page, "1234.560" in the API or a CSV.
 */
final class LeakNeedles
{
    /** Columns holding money, by input file. */
    private const array MONEY = [
        'fillups' => ['total_cost'],
        'expenses' => ['amount'],
        'maintenance' => ['cost'],
        'documents' => ['cost'],
        'valuations' => ['amount'],
        'vehicles' => ['purchase_price', 'sale_price'],
        'finance' => [
            'first_payment', 'regular_payment', 'final_payment', 'cash_price', 'customer_deposit',
            'dealer_contribution', 'amount_of_credit', 'documentation_fee', 'option_to_purchase_fee',
        ],
        'finance_events' => ['amount'],
        'incidents' => ['excess', 'payout'],
    ];

    /** Columns holding numbers that are not money, which an amount must not equal. */
    private const array NOT_MONEY = [
        'fillups' => ['odometer_km', 'volume', 'price_per_unit'],
        'readings' => ['odometer_km'],
        'maintenance' => ['odometer_km'],
        'trips' => ['typed_miles_one_way', 'distance_km'],
        'finance' => ['apr', 'start_odometer_km', 'annual_mileage_allowance', 'excess_mileage_charge'],
        'schedules' => ['interval_km', 'baseline_done_km'],
        'rate_sets' => ['car_rate', 'car_threshold', 'car_rate_after'],
    ];

    /**
     * Amounts below this are left out: fill-ups crowd £30–£70 so densely
     * that a segment's or a month's sum often equals one by chance.
     */
    private const int FLOOR = 100;

    /** Model names that appear nowhere else in the app's text. */
    private const array MODELS = ['ev' => 'Niro', 'corolla' => 'Corolla'];

    /** @var array<string, string> "1234.56" => what it is */
    public readonly array $amounts;
    /** @var array<string, string> exact text => what it is */
    public readonly array $texts;

    /**
     * @param array<string, string> $amounts
     * @param array<string, string> $texts
     */
    private function __construct(array $amounts, array $texts)
    {
        $this->amounts = $amounts;
        $this->texts = $texts;
    }

    public static function for(string $person, string $inputs = GoldenHousehold::INPUTS): self
    {
        $model = Household::PEOPLE[$person];
        $csv = static fn (string $name): array => ExpectedSkeleton::read($inputs . '/' . $name . '.csv');

        $hidden = [];
        $allowed = [];
        foreach (self::MONEY as $file => $columns) {
            foreach ($csv($file) as $row) {
                $vehicle = $row['vehicle'];
                $own = $file === 'fillups' && $row['by'] === $person && in_array($vehicle, $model['vehicles'], true);
                $visible = $own || in_array($vehicle, $model['costs'], true);
                foreach ($columns as $column) {
                    $value = self::cents($row[$column] ?? '');
                    if ($value === null) {
                        continue;
                    }
                    if ($visible) {
                        $allowed[$value] = true;
                    } else {
                        $hidden[$value] ??= "{$file}.{$column} on {$vehicle}";
                    }
                }
            }
        }
        $other = [];
        foreach (self::NOT_MONEY as $file => $columns) {
            foreach ($csv($file) as $row) {
                foreach ($columns as $column) {
                    $value = self::cents($row[$column] ?? '');
                    if ($value !== null) {
                        $other[$value] = true;
                    }
                }
            }
        }
        $amounts = [];
        foreach ($hidden as $value => $label) {
            $large = BigDecimal::of($value)->isGreaterThanOrEqualTo(self::FLOOR);
            if ($large && !isset($allowed[$value]) && !isset($other[$value])) {
                $amounts[$value] = $label;
            }
        }

        $texts = [];
        foreach ($csv('vehicles') as $row) {
            if (in_array($row['vehicle'], $model['vehicles'], true)) {
                continue;
            }
            $texts[$row['registration']] = 'the registration of ' . $row['vehicle'];
            $texts[str_replace(' ', '', $row['registration'])] = 'the registration of ' . $row['vehicle'];
            if (isset(self::MODELS[$row['vehicle']])) {
                $texts[self::MODELS[$row['vehicle']]] = 'the model of ' . $row['vehicle'];
            }
        }
        $elsewhere = '';
        foreach (glob($inputs . '/*.csv') ?: [] as $file) {
            if (basename($file) !== 'trips.csv') {
                $elsewhere .= strtolower((string) file_get_contents($file));
            }
        }
        // And the app's own words: a place called York is in "America/New York".
        $elsewhere .= strtolower(str_replace('_', ' ', implode(' ', DateTimeZone::listIdentifiers())));
        foreach (glob(dirname(__DIR__, 3) . '/translations/*.en*') ?: [] as $file) {
            $elsewhere .= strtolower((string) file_get_contents($file));
        }
        foreach ($csv('trips') as $row) {
            $theirs = $row['user'] === $person;
            $sees = in_array($row['vehicle'], $model['vehicles'], true) && ($theirs || $model['othersTrips']);
            if ($sees) {
                continue;
            }
            foreach (['from', 'to'] as $column) {
                $place = $row[$column];
                if ($place !== '' && !str_contains($elsewhere, strtolower($place))) {
                    $texts[$place] = "a trip's place on {$row['vehicle']} by {$row['user']}";
                }
            }
        }

        return new self($amounts, $texts);
    }

    /**
     * The needles found in a response body: needle => a snippet around it.
     *
     * @return array<string, string>
     */
    public function find(string $body): array
    {
        $found = [];
        if ($this->amounts !== []) {
            $number = '/(?<![\d.,])(\d{1,3}(?:,\d{3})+|\d+)\.(\d{2})(\d?)(?!\d)/';
            preg_match_all($number, $body, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($matches as $m) {
                if ($m[3][0] !== '' && $m[3][0] !== '0') {
                    continue;
                }
                $value = str_replace(',', '', $m[1][0]) . '.' . $m[2][0];
                if (isset($this->amounts[$value]) && !isset($found[$value])) {
                    $found[$value] = self::snippet($body, $m[0][1]);
                }
            }
        }
        foreach (array_keys($this->texts) as $text) {
            // A whole word: "York" is not in "America/New_York".
            $word = '/(?<![\w-])' . preg_quote((string) $text, '/') . '(?![\w-])/';
            if (preg_match($word, $body, $m, PREG_OFFSET_CAPTURE) === 1) {
                $found[(string) $text] = self::snippet($body, $m[0][1]);
            }
        }

        return $found;
    }

    /**
     * Add the app's own figures (totals, rates) for vehicles whose costs
     * the person may not see: a page can show a total without its lines.
     *
     * @param list<array{vehicles: list<string>, value: string, label: string}> $figures
     */
    public function withFigures(string $person, array $figures): self
    {
        $costs = Household::PEOPLE[$person]['costs'];
        $amounts = $this->amounts;
        foreach ($figures as $figure) {
            $value = self::cents($figure['value']);
            if ($value === null || array_diff($figure['vehicles'], $costs) === []) {
                continue;
            }
            if (BigDecimal::of($value)->isGreaterThanOrEqualTo(self::FLOOR)) {
                $amounts[$value] ??= $figure['label'];
            }
        }

        return new self($amounts, $this->texts);
    }

    /** True for a needle that names a vehicle (a registration or model). */
    public function namesVehicle(string $needle): bool
    {
        return str_starts_with($this->texts[$needle] ?? '', 'the registration of ')
            || str_starts_with($this->texts[$needle] ?? '', 'the model of ');
    }

    /** What a needle is. */
    public function label(string $needle): string
    {
        return $this->amounts[$needle] ?? $this->texts[$needle] ?? $needle;
    }

    /**
     * Only these needles: the ones the control saw somewhere, so each is
     * in a form the app really prints.
     *
     * @param array<string, true> $seen
     */
    public function only(array $seen): self
    {
        return new self(
            array_intersect_key($this->amounts, $seen),
            array_intersect_key($this->texts, $seen),
        );
    }

    private static function cents(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (string) BigDecimal::of($value)->abs()->toScale(2, RoundingMode::HalfUp);
    }

    private static function snippet(string $body, int $at): string
    {
        $start = max(0, $at - 60);

        return trim((string) preg_replace('/\s+/', ' ', substr($body, $start, 140)));
    }
}
