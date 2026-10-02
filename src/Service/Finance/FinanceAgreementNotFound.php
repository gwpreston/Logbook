<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use RuntimeException;

/**
 * No such agreement on this vehicle, or none the user may see (spec.md §7.32
 * *Access*): answered as 404.
 */
final class FinanceAgreementNotFound extends RuntimeException
{
}
