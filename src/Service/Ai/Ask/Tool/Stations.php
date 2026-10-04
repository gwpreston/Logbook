<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\User\User;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Station\GradeStats;
use Logbook\Service\Station\StationListing;
use Logbook\Service\Station\StationService;
use Logbook\Support\Money\Money;

/**
 * `stations(query?, favourites_only?)` (spec.md §7.26, §7.33): where the
 * user fills up and what they paid there, from their own fill-ups on the
 * vehicles they can see, for "Where do I usually fill up?" and "What's the
 * cheapest I've paid at Tesco?". Never their places.
 */
final readonly class Stations implements AskTool
{
    /** Stations returned at most, most visited first. */
    private const int LIMIT = 15;

    public function __construct(
        private ToolKit $kit,
        private StationService $stations,
    ) {
    }

    public function name(): string
    {
        return 'stations';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Fuel stations and chargers the user has used: visits, last visit, and per grade what they paid there '
            . '(spend, average price per litre or kWh weighted by volume, cheapest and last price, with dates). '
            . 'Most visited first. Search by name, brand or postcode.',
            [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Part of a name, brand or postcode, e.g. "Tesco".'],
                    'favourites_only' => ['type' => 'boolean', 'description' => 'Only the user\'s favourite stations.'],
                ],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return $this->stations->enabled();
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $query = mb_substr(trim($arguments->string('query') ?? ''), 0, 100);
        $favourites = ($arguments->values['favourites_only'] ?? false) === true;
        $rows = array_values(array_filter(
            $this->stations->listing($user, $query),
            static fn (StationListing $row): bool => $favourites
                ? $row->favourite
                : $row->summary !== null || $row->favourite,
        ));
        usort(
            $rows,
            static fn (StationListing $a, StationListing $b): int => ($b->summary->visits ?? 0) <=> ($a->summary->visits ?? 0),
        );
        $rows = array_slice($rows, 0, self::LIMIT);

        $figures = [];
        $stations = [];
        foreach ($rows as $row) {
            $stations[] = $this->station($row);
            $main = $row->summary?->mainGrade();
            if ($main !== null && $main->averagePrice !== null && count($figures) < 3) {
                $figures[] = $this->kit->format->unitPrice($main->averagePrice, $main->currency, $main->fuel->kind());
            }
        }

        return new ToolResult(
            ['stations' => $stations, 'query' => $query === '' ? null : $query, 'favourites_only' => $favourites],
            $this->kit->source(array_values(array_filter([$this->kit->t('ask.tool.stations'), $query]))),
            $figures,
            $query === '' ? '/stations' : $this->kit->link('/stations', ['q' => $query]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function station(StationListing $row): array
    {
        $station = $row->station;
        $summary = $row->summary;

        return [
            'id' => $station->id,
            'name' => $station->data->name,
            'brand' => $station->data->brand,
            'postcode' => $station->data->postcode,
            'favourite' => $row->favourite,
            'visits' => $summary->visits ?? 0,
            'last_visit' => $summary?->lastVisit === null ? null : $this->kit->format->instantDate($summary->lastVisit),
            'paid' => $summary === null ? [] : array_map($this->grade(...), $summary->grades),
            'link' => $this->kit->link('/stations/' . $station->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function grade(GradeStats $stats): array
    {
        $kind = $stats->fuel->kind();
        $price = fn (?string $value): ?array => $value === null ? null : [
            'per_unit' => $value,
            'display' => $this->kit->format->unitPrice($value, $stats->currency, $kind, true),
        ];

        $priced = $stats->averagePrice !== null;
        $cheapest = $price($stats->cheapestPrice);
        if ($cheapest !== null && $stats->cheapestOn !== null) {
            $cheapest['on'] = $this->kit->format->instantDate($stats->cheapestOn);
        }

        return [
            'grade' => $stats->grade === null
                ? $this->kit->t('fuel.fuel.' . $stats->fuel->value)
                : $this->kit->t($stats->grade->shortLabelKey()),
            'visits' => $stats->visits,
            // Amounts only where the user may see them (spec.md §7.21).
            'spend' => $priced ? $this->kit->money(Money::of($stats->spend, $stats->currency)) : null,
            'average_price' => $price($stats->averagePrice),
            'cheapest_price' => $cheapest,
            'last_price' => $price($stats->lastPrice),
        ];
    }
}
