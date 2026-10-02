<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use DateTimeImmutable;
use Logbook\Domain\Finance\AgreementStatus;

/**
 * How an agreement ended (spec.md §7.32 *Ending*), as the *End agreement*
 * form or the archive page gives it. Amounts are canonical decimals.
 */
final readonly class EndAgreement
{
    public function __construct(
        /** Settled, completed, handed back or ended: never active. */
        public AgreementStatus $outcome,
        public DateTimeImmutable $endedOn,
        /** Settled early: what paid it off. */
        public ?string $settlement = null,
        /** Handed back or lease ended: logged as a *Finance and lease* expense on the end date (#127). */
        public ?string $excessCharge = null,
        public ?string $damageCharge = null,
    ) {
    }
}
