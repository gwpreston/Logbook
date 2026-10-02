<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use RuntimeException;

/**
 * A vehicle has at most one active agreement (spec.md §6 FinanceAgreement):
 * "This vehicle already has an active agreement. End it first."
 */
final class AgreementAlreadyActive extends RuntimeException
{
}
