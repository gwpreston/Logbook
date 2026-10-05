<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use DateTimeImmutable;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Service\Vehicle\ValuePoint;

/**
 * The vehicle's Finance tab (spec.md §7.32 *Finance tab*): one agreement in
 * the prototype's cards (the active one at `/finance`, the named one at
 * `/finance/{agreement}`), the Purchase and Value & equity cards' figures,
 * and the earlier agreements below. With no agreement to show, the empty
 * card.
 */
final readonly class FinancePage
{
    /**
     * @param list<AgreementView> $earlier ended agreements, newest first, the shown one left out
     * @param array<string, int> $marks each missed mark's event id by the due date it concerns (Y-m-d), for its *Undo*
     */
    public function __construct(
        /** The agreement shown in the cards; null for the empty card. */
        public ?AgreementView $shown,
        public array $earlier,
        /** *Add finance*: no active agreement and the vehicle not archived. */
        public bool $canAdd,
        /** The vehicle's *Mileage when bought* reading (§7.1). */
        public ?OdometerReading $purchaseReading,
        /** The vehicle's current value (§7.1): the sale, else the latest valuation. */
        public ?ValuePoint $currentValue,
        public array $marks,
        /** The vehicle owner's today. */
        public DateTimeImmutable $today,
        /** Whether the shown agreement takes marks, extras, quotes and *End*. */
        public bool $open,
    ) {
    }
}
