<?php

declare(strict_types=1);

namespace Logbook\Repository;

use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelGrade;
use Psr\Log\LoggerInterface;

/**
 * Reads a stored fuel grade code (spec.md §6). A code this release does not
 * know (removed later, or written by hand), or one that no longer fits its
 * row's fuel, reads as "not recorded" and is logged, so it never breaks a page.
 */
final readonly class StoredGrade
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function read(?string $code, ?Fuel $family, string $table, int $id): ?FuelGrade
    {
        if ($code === null || $code === '') {
            return null;
        }

        $grade = FuelGrade::tryFrom($code);
        if ($grade !== null && $grade->family() === $family) {
            return $grade;
        }

        $this->logger->warning('Ignoring stored fuel grade that does not fit.', [
            'table' => $table,
            'id' => $id,
            'grade' => $code,
            'fuel' => $family?->value,
        ]);

        return null;
    }
}
