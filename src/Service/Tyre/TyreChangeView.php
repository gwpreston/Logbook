<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Tyre\TyreChange;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * A tyre change as listed: its summary, and the linked service record that
 * carries its cost (null when none, or while maintenance is off).
 */
final readonly class TyreChangeView
{
    public function __construct(
        public TyreChange $change,
        public TranslatableMessage $summary,
        public ?MaintenanceEntry $record = null,
    ) {
    }
}
