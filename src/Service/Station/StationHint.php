<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use Logbook\Domain\User\User;
use Logbook\Support\Display\DisplayFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Last time here: £1.389/L E10 95, 12 Sep" (spec.md §7.33 *Fill-up
 * form*): the user's latest fill-up at a station whose amounts they may
 * see, on a vehicle they can see. A hint under the field, never a prefill.
 */
final readonly class StationHint
{
    public function __construct(
        private StationService $stations,
        private DisplayFormatter $formatter,
        private TranslatorInterface $translator,
    ) {
    }

    public function forStation(User $user, int $stationId): ?string
    {
        return $this->forStations($user, [$stationId])[$stationId] ?? null;
    }

    /**
     * @param list<int> $stationIds
     * @return array<int, string> keyed by station id; none for a station never visited
     */
    public function forStations(User $user, array $stationIds): array
    {
        if ($stationIds === []) {
            return [];
        }
        $last = [];
        foreach ($this->stations->visits($user, $stationIds) as $visit) {
            if ($visit->amountVisible) {
                $last[$visit->stationId()] = $visit;
            }
        }

        $hints = [];
        foreach ($last as $id => $visit) {
            $data = $visit->entry->data;
            $hints[$id] = $this->translator->trans('stations.last_time', [
                'price' => $this->formatter->unitPrice($data->pricePerUnit, $visit->currency, $data->fuel->kind()),
                'grade' => $data->grade === null
                    ? $this->translator->trans('fuel.fuel.' . $data->fuel->value)
                    : $this->translator->trans($data->grade->shortLabelKey()),
                'date' => $this->formatter->instantDate($data->filledAt),
            ]);
        }

        return $hints;
    }
}
