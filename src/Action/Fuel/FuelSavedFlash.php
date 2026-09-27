<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Fuel\FuelService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Session\Session;

/**
 * The notices after saving a fill-up: success (with the fill's economy when
 * it closes a full-to-full segment) and any odometer plausibility warning.
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
        $segment = null;
        foreach ($this->fuel->history($vehicle)->fills as $fill) {
            if ($fill->entry->id === $entry->id) {
                $segment = $fill->segment;
            }
        }

        if ($segment === null) {
            $session->flash('success', $key);
        } else {
            $session->flash('success', $key . '_economy', [
                'economy' => $this->formatter->economy($segment->distanceKm, $segment->volume, $entry->data->fuel->isElectric()),
            ]);
        }

        $this->warnings->queue($session, $this->fuel->odometerWarning($vehicle, $entry));
    }
}
