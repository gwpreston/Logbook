<?php

declare(strict_types=1);

namespace Logbook\Action\Odometer;

use Logbook\Service\Odometer\OdometerWarning;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Session\Session;

/**
 * Queues the "saved, but this reading looks odd" notice after a save. The
 * reading is kept either way: plausibility warnings never block.
 */
final readonly class OdometerWarningFlash
{
    public function __construct(private DisplayFormatter $formatter)
    {
    }

    public function queue(Session $session, ?OdometerWarning $warning): void
    {
        if ($warning === null) {
            return;
        }

        $session->flash('warning', 'odometer.warning.' . $warning->type . '_saved', $this->params($warning));
    }

    /**
     * Message parameters describing a warning, formatted for display.
     *
     * @return array<string, string>
     */
    public function params(OdometerWarning $warning): array
    {
        return [
            'previous' => $this->formatter->distance($warning->previous->readingKm),
            'date' => $this->formatter->instantDate($warning->previous->recordedAt),
            'distance' => $this->formatter->distance(ltrim($warning->distanceKm, '-')),
        ];
    }
}
