<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use Logbook\Domain\Maintenance\MaintenanceEntry;

/**
 * The summary's *Servicing* line (spec.md §7.19): how many service and
 * repair records there are, the last service or oil change, and how many
 * records carry paperwork.
 */
final readonly class ServicingSummary
{
    public function __construct(
        public int $records,
        /** The latest `service` or `oil` record, or null. */
        public ?MaintenanceEntry $lastService,
        public int $withPaperwork,
    ) {
    }
}
