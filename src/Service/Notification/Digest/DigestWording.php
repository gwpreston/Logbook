<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Digest;

use Logbook\Support\Api\Serializer;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Words the monthly briefing (spec.md §7.11 *The monthly briefing*) for
 * the recipient, inside their display scope (NotificationComposer): each
 * figure a line of its own, so a short channel cuts between them, and the
 * webhook's raw figures beside the text.
 */
final readonly class DigestWording
{
    /** Within this many percent of the average, it reads "about the same". */
    public const int SAME_WITHIN = 5;

    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $formatter,
    ) {
    }

    /**
     * "• Golf: 2 open issues", one per vehicle.
     *
     * @param list<OpenIssues> $issues
     * @return list<string>
     */
    public function issueLines(array $issues): array
    {
        return array_map(fn (OpenIssues $i): string => $this->translator->trans('notifications.digest.issues_line', [
            'vehicle' => $i->vehicle->name(),
            'count' => $i->count,
        ]), $issues);
    }

    /**
     * The *Last month* section's lines: a heading, then each vehicle's
     * distance, spend and cost per distance, then the fleet line.
     *
     * @return list<string>
     */
    public function lastMonthLines(LastMonth $lastMonth): array
    {
        $lines = [$this->translator->trans('notifications.digest.last_month', [
            'month' => $this->formatter->skeleton($lastMonth->month, 'yMMMM'),
        ])];
        foreach ($lastMonth->lines as $line) {
            array_push($lines, ...$this->vehicleLines($line));
        }
        if ($lastMonth->hasFleet()) {
            $lines[] = $this->fleetLine($lastMonth);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function vehicleLines(LastMonthLine $line): array
    {
        $vehicle = $line->vehicle->name();
        $lines = [];
        if ($line->distanceKm !== null) {
            $lines[] = $this->translator->trans('notifications.digest.distance', [
                'vehicle' => $vehicle,
                'distance' => $this->formatter->distance($line->distanceKm),
                'average' => $this->formatter->distance($line->distanceAverageKm),
            ] + self::trend($line->distanceKm, $line->distanceAverageKm));
        }
        $costs = $line->costs;
        if ($costs === null) {
            return $lines;
        }
        $average = $costs->average?->toDecimal(Money::SCALE);
        // A month with nothing spent says so, without a comparison (#367).
        $lines[] = $costs->spend->isZero()
            ? $this->translator->trans('notifications.digest.spend_none', ['vehicle' => $vehicle])
            : $this->translator->trans('notifications.digest.spend', [
            'vehicle' => $vehicle,
            'amount' => $this->formatter->money($costs->spend),
            'largest' => $costs->largest === null ? 'none' : 'named',
            'entry' => $costs->largest === null ? '' : $this->translator->trans($costs->largest->kindKey),
            'entry_amount' => $costs->largest === null ? '' : $this->formatter->money($costs->largest->amount),
            'average' => $costs->average === null ? '' : $this->formatter->money($costs->average),
        ] + self::trend($costs->spend->toDecimal(Money::SCALE), $average));
        $lines[] = $this->translator->trans('notifications.digest.cost_per_distance', [
            'vehicle' => $vehicle,
            'cost' => $costs->costPerKm === null ? '—' : $this->formatter->perDistance($costs->costPerKm, $costs->currency),
            'average' => $costs->costPerKmAverage === null
                ? ''
                : $this->formatter->perDistance($costs->costPerKmAverage, $costs->currency),
        ] + self::trend($costs->costPerKm, $costs->costPerKmAverage));

        return $lines;
    }

    private function fleetLine(LastMonth $lastMonth): string
    {
        $parts = [];
        if ($lastMonth->fleetDistanceKm !== null) {
            $parts[] = $this->translator->trans('notifications.digest.fleet_distance', [
                'distance' => $this->formatter->distance($lastMonth->fleetDistanceKm),
            ]);
        }
        if ($lastMonth->fleetSpend !== []) {
            $parts[] = $this->translator->trans('notifications.digest.fleet_spend', [
                'amounts' => implode(', ', array_map(
                    fn (Money $m): string => $this->formatter->money($m),
                    $lastMonth->fleetSpend,
                )),
            ]);
        }

        return $this->translator->trans('notifications.digest.fleet', ['figures' => implode('; ', $parts)]);
    }

    /**
     * The *Insights* section's lines: a heading, then each insight, the AI
     * ones marked.
     *
     * @param non-empty-list<DigestInsight> $insights
     * @return list<string>
     */
    public function insightLines(array $insights): array
    {
        $lines = [$this->translator->trans('notifications.digest.insights', ['count' => count($insights)])];
        foreach ($insights as $insight) {
            $key = $insight->source === DigestInsight::AI ? 'ai_insight_line' : 'insight_line';
            $lines[] = $this->translator->trans('notifications.digest.' . $key, [
                'title' => $insight->title,
                'body' => $insight->body,
            ]);
        }

        return $lines;
    }

    /**
     * The webhook's `last_month`: raw figures (canonical kilometres and
     * decimal strings, as the REST API) and the lines as sent; the amounts
     * only with `ViewCosts`.
     *
     * @return list<array<string, mixed>>
     */
    public function lastMonthJson(LastMonth $lastMonth): array
    {
        $out = [];
        foreach ($lastMonth->lines as $line) {
            $row = [
                'vehicle_id' => $line->vehicle->id,
                'vehicle' => $line->vehicle->name(),
                'month' => $lastMonth->month->format('Y-m'),
                'distance' => Serializer::dec($line->distanceKm, Serializer::QUANTITY_SCALE),
                'distance_average' => Serializer::dec($line->distanceAverageKm, Serializer::QUANTITY_SCALE),
            ];
            $costs = $line->costs;
            if ($costs !== null) {
                $row += [
                    'currency' => $costs->currency,
                    'spend' => $costs->spend->toDecimal(Serializer::QUANTITY_SCALE),
                    'spend_average' => $costs->average?->toDecimal(Serializer::QUANTITY_SCALE),
                    'cost_per_distance' => Serializer::dec($costs->costPerKm, Serializer::PER_KM_SCALE),
                    'cost_per_distance_average' => Serializer::dec($costs->costPerKmAverage, Serializer::PER_KM_SCALE),
                ];
            }
            $row['display'] = implode("\n", array_map(
                self::unbulleted(...),
                $this->vehicleLines($line),
            ));
            $out[] = $row;
        }

        return $out;
    }

    /**
     * The webhook's `fleet`, or null with fewer than two vehicles.
     *
     * @return array{distance: string|null, spend: array<string, string>, display: string}|null
     */
    public function fleetJson(LastMonth $lastMonth): ?array
    {
        if (!$lastMonth->hasFleet()) {
            return null;
        }

        return [
            'distance' => Serializer::dec($lastMonth->fleetDistanceKm, Serializer::QUANTITY_SCALE),
            'spend' => array_map(
                static fn (Money $m): string => $m->toDecimal(Serializer::QUANTITY_SCALE),
                $lastMonth->fleetSpend,
            ),
            'display' => self::unbulleted($this->fleetLine($lastMonth)),
        ];
    }

    /**
     * A line without its leading "• " (a prefix, not a character mask: a
     * name may start with a byte of the bullet, as "€" does).
     */
    private static function unbulleted(string $line): string
    {
        return str_starts_with($line, '• ') ? substr($line, strlen('• ')) : $line;
    }

    /**
     * A comparison as ICU parameters: `trend` is `more`, `less`, `same`, or
     * `none` without an average; `percent` is from the figures shown.
     *
     * @return array{trend: string, percent: int}
     */
    public static function trend(?string $value, ?string $average): array
    {
        if ($value === null || $average === null || Decimal::compare($average, '0') <= 0) {
            return ['trend' => 'none', 'percent' => 0];
        }
        $percent = (int) round(abs(((float) $value - (float) $average) / (float) $average * 100));
        if ($percent <= self::SAME_WITHIN) {
            return ['trend' => 'same', 'percent' => $percent];
        }

        return ['trend' => Decimal::compare($value, $average) > 0 ? 'more' : 'less', 'percent' => $percent];
    }
}
