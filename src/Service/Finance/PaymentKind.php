<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

/**
 * Which part of an agreement a scheduled payment is (spec.md §7.32
 * *Schedule*).
 */
enum PaymentKind: string
{
    /** A lease's initial rental, on the agreement date. */
    case InitialRental = 'initial_rental';
    /** One of the regular monthly payments (the first may differ). */
    case Regular = 'regular';
    /** The final payment: a PCP's optional final payment (GFV), an HP final payment, a lease's last rental. */
    case Final = 'final';
}
