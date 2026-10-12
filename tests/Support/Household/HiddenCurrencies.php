<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Household;

use Brick\Math\BigDecimal;
use Logbook\Tests\Support\Golden\ExpectedSkeleton;
use Logbook\Tests\Support\Golden\GoldenHousehold;

/**
 * The leak sweep's second check (Phase 45.2), for the figures too small or
 * too derived to search for one by one: segment costs, rates, prices,
 * station totals. The household's currencies split it cleanly (pounds for
 * the Golf, EV and Focus; euros for the Corolla), so in a currency in which
 * a person may see no vehicle's costs, every amount they are shown must be
 * one they typed themselves (spec.md §7.21: "they see the amounts they
 * typed"; anything derived stays hidden), or zero.
 *
 * Pages and display strings are checked by the currency sign ("£0.136/mi",
 * "−€12.00"); the API's raw numbers by their keys, in any object whose
 * `currency` (or its parent's) is hidden.
 */
final readonly class HiddenCurrencies
{
    private const array SIGNS = ['GBP' => '£', 'EUR' => '€'];

    /** A key holding money in the API (a cost, price, rate or total). */
    private const string MONEY_KEY = '/(cost|amount|spend|price|payment|excess|payout|per_distance|^total$|valuation)/';
    /** Keys that look like money but are not. */
    private const string NOT_MONEY_KEY
        = '/(distance_|_km|volume|count|unit|currency|display|status|source|kind|without|_id$|^id$)/';

    /**
     * @param list<string> $hidden currency codes
     * @param array<string, true> $own amounts the person typed, normalised
     */
    private function __construct(public array $hidden, private array $own)
    {
    }

    public static function for(string $person, string $inputs = GoldenHousehold::INPUTS): self
    {
        $model = Household::PEOPLE[$person];
        $currency = array_column(ExpectedSkeleton::read($inputs . '/vehicles.csv'), 'currency', 'vehicle');
        $seen = [];
        foreach ($model['costs'] as $vehicle) {
            $seen[$currency[$vehicle]] = true;
        }
        $hidden = array_values(array_diff(array_keys(self::SIGNS), array_keys($seen)));
        $own = [];
        foreach (ExpectedSkeleton::read($inputs . '/fillups.csv') as $row) {
            if ($row['by'] === $person && in_array($row['vehicle'], $model['vehicles'], true)) {
                $own[self::normal($row['total_cost'])] = true;
                $own[self::normal($row['price_per_unit'])] = true;
            }
        }

        return new self($hidden, $own);
    }

    /**
     * Amounts in a hidden currency that the person did not type: "£12.34" => a snippet around it.
     *
     * @return array<string, string>
     */
    public function find(string $body): array
    {
        if ($this->hidden === []) {
            return [];
        }
        $body = str_replace(['£', '€', '−', '&pound;', '&euro;', '&#8722;'], ['£', '€', '−', '£', '€', '−'], $body);
        $signs = implode('', array_map(fn (string $c): string => self::SIGNS[$c], $this->hidden));
        $found = [];
        $amount = '/([' . $signs . '])\s?(\d{1,3}(?:,\d{3})+|\d+)(\.\d+)?/u';
        preg_match_all($amount, $body, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($matches as $m) {
            $value = str_replace(',', '', $m[2][0]) . ($m[3][0] ?? '');
            if ($this->allowed($value)) {
                continue;
            }
            $found[$m[1][0] . $value] ??= self::snippet($body, $m[0][1]);
        }
        if (str_starts_with(ltrim($body), '{') || str_starts_with(ltrim($body), '[')) {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $this->walk($json, null, '', $found);
            }
        }

        return $found;
    }

    /**
     * @param array<mixed> $node
     * @param array<string, string> $found
     */
    private function walk(array $node, ?string $currency, string $path, array &$found): void
    {
        if (is_string($node['currency'] ?? null)) {
            $currency = $node['currency'];
        }
        foreach ($node as $key => $value) {
            $at = $path . '.' . $key;
            if (is_array($value)) {
                $this->walk($value, $currency, $at, $found);
                continue;
            }
            if (
                !is_string($key)
                || $currency === null
                || !in_array($currency, $this->hidden, true)
                || preg_match(self::MONEY_KEY, $key) !== 1
                || preg_match(self::NOT_MONEY_KEY, $key) === 1
                || !(is_string($value) || is_int($value) || is_float($value))
                || !is_numeric((string) $value)
            ) {
                continue;
            }
            if (!$this->allowed((string) $value)) {
                $found[$currency . ' ' . $value . ' at ' . ltrim($at, '.')] ??= $key . ': ' . json_encode($value);
            }
        }
    }

    private function allowed(string $value): bool
    {
        $normal = self::normal($value);

        return $normal === '0' || isset($this->own[$normal]);
    }

    private static function normal(string $value): string
    {
        return (string) BigDecimal::of($value)->abs()->strippedOfTrailingZeros();
    }

    private static function snippet(string $body, int $at): string
    {
        return trim((string) preg_replace('/\s+/', ' ', substr($body, max(0, $at - 60), 140)));
    }
}
