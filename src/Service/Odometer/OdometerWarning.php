<?php

declare(strict_types=1);

namespace Logbook\Service\Odometer;

use Logbook\Domain\Odometer\OdometerReading;

/**
 * Something odd about a reading compared with the one before it. Warnings
 * never block saving: odometers get replaced, and old readings get typed in
 * out of order.
 */
final readonly class OdometerWarning
{
    public const string BACKWARDS = 'backwards';
    public const string JUMP = 'jump';

    public function __construct(
        /** self::BACKWARDS or self::JUMP */
        public string $type,
        public OdometerReading $previous,
        /** Kilometres from the previous reading (negative when going backwards). */
        public string $distanceKm,
        /** Days since the previous reading (at least 1, for the jump rule). */
        public float $days,
    ) {
    }

    public function isBackwards(): bool
    {
        return $this->type === self::BACKWARDS;
    }
}
