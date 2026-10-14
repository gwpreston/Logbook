<?php

declare(strict_types=1);

namespace Logbook\Service\Working;

use Logbook\Service\Fuel\FillEconomy;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Working;
use Logbook\Support\WorkingInput;
use Logbook\Support\WorkingLink;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The working of the economy of one stretch between full fill-ups (spec.md
 * §7.3, §8 *Show the working*): the full fill-ups at each end, the fuel
 * bought between them and the distance, from the EconomySegment the row
 * shows.
 */
final readonly class EconomyWorking
{
    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $format,
    ) {
    }

    /**
     * Null for a fill-up that closes no measured stretch.
     */
    public function forFill(FillEconomy $fill): ?Working
    {
        $segment = $fill->segment;
        $opening = $segment?->opening;
        if ($segment === null || $opening === null) {
            return null;
        }
        $closing = $fill->entry;
        $kind = $closing->data->fuel->kind();
        $vehicleId = $closing->vehicleId;
        $distance = $this->format->distance($segment->distanceKm);
        $volume = $this->format->quantity($segment->volume, $kind);
        $economy = $this->format->economy($segment->distanceKm, $segment->volume, $kind);
        $opened = $this->format->instantDate($opening->data->filledAt);
        $closed = $this->format->instantDate($closing->data->filledAt);

        $steps = [$this->t('working.economy.sum', [
            'distance' => $distance,
            'opened' => $opened,
            'openedOdometer' => $this->format->distance($opening->data->odometerKm),
            'closed' => $closed,
            'closedOdometer' => $this->format->distance($closing->data->odometerKm),
            'volume' => $volume,
            'fills' => $segment->fills,
            'economy' => $economy,
        ])];
        $notes = $segment->fills > 1 ? [$this->t('working.economy.partials')] : [];

        return new Working(
            [
                new WorkingInput(
                    $this->t('working.economy.opening'),
                    $this->t('working.economy.fill', [
                        'date' => $opened,
                        'odometer' => $this->format->distance($opening->data->odometerKm),
                    ]),
                    null,
                    new WorkingLink('fuel.edit', ['id' => $vehicleId, 'entry' => $opening->id]),
                ),
                new WorkingInput(
                    $this->t('working.economy.closing'),
                    $this->t('working.economy.fill', [
                        'date' => $closed,
                        'odometer' => $this->format->distance($closing->data->odometerKm),
                    ]),
                    null,
                    new WorkingLink('fuel.edit', ['id' => $vehicleId, 'entry' => $closing->id]),
                ),
                new WorkingInput($this->t('working.economy.fuel'), $volume),
                new WorkingInput($this->t('working.economy.distance'), $distance),
            ],
            $steps,
            $notes,
            $economy,
            null,
        );
    }

    /**
     * @param array<string, int|string> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params);
    }
}
