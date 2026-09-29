<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;

/**
 * "MOT valid until 14 Jun 2027" (spec.md §7.19): a current inspection or
 * pollution document with an expiry.
 */
final readonly class InspectionLine
{
    public function __construct(
        public ComplianceType $type,
        public DateTimeImmutable $expiresOn,
        public bool $expired,
    ) {
    }
}
