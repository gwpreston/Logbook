<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Fuel\EconomyVerdict;
use Logbook\Service\Fuel\FuelService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Session\Session;

/**
 * The notices after saving a fill-up: success (with the fill's economy when
 * it closes a full-to-full segment), its economy check when flagged
 * (spec.md §7.3) and any odometer plausibility warning.
 */
final readonly class FuelSavedFlash
{
    public function __construct(
        private FuelService $fuel,
        private DisplayFormatter $formatter,
        private OdometerWarningFlash $warnings,
    ) {
    }

    public function queue(Session $session, Vehicle $vehicle, FuelEntry $entry, string $key): void
    {
        $history = $this->fuel->history($vehicle);
        $segment = null;
        foreach ($history->fills as $fill) {
            if ($fill->entry->id === $entry->id) {
                $segment = $fill->segment;
            }
        }
        $electric = $entry->data->fuel->isElectric();

        if ($segment === null) {
            $session->flash('success', $key);
        } else {
            $session->flash('success', $key . '_economy', [
                'economy' => $this->formatter->economy($segment->distanceKm, $segment->volume, $electric),
            ]);

            // The fill-up is saved either way; a flag only asks to check it.
            $check = $this->fuel->checks($history)->for($entry->id);
            if ($check !== null && $check->isFlagged() && $check->baseline !== null) {
                $key = $check->verdict === EconomyVerdict::More ? 'fuel.check.saved_more' : 'fuel.check.saved_less';
                $session->flash('warning', $key, [
                    'percent' => $this->formatter->percent($check->difference()),
                    'economy' => $this->formatter->economy($segment->distanceKm, $segment->volume, $electric),
                    'usual' => $this->formatter->economy('100', $check->baseline, $electric),
                ]);
            }
        }

        $this->warnings->queue($session, $this->fuel->odometerWarning($vehicle, $entry));
    }
}
