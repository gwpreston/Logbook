<?php

declare(strict_types=1);

namespace Logbook\Service\Compliance;

use RuntimeException;

/**
 * No such compliance document on this vehicle.
 */
final class ComplianceDocumentNotFound extends RuntimeException
{
}
