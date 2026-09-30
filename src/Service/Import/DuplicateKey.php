<?php

declare(strict_types=1);

namespace Logbook\Service\Import;

use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Trip\TripData;
use Logbook\Support\Number\Decimal;
use LogicException;

/**
 * What makes two entries "the same": the CSV import's duplicate rule
 * (spec.md §7.13), which the API's safe retries use too (§7.20).
 */
final class DuplicateKey
{
    public static function of(object $data): string
    {
        $lower = static fn (?string $text): string => mb_strtolower(trim($text ?? ''));

        return match (true) {
            $data instanceof FuelEntryData => implode('|', [
                'fuel',
                $data->filledAt->getTimestamp(),
                Decimal::trim($data->odometerKm),
            ]),
            $data instanceof OdometerReadingData => implode('|', [
                'odometer',
                $data->recordedAt->getTimestamp(),
                Decimal::trim($data->readingKm),
            ]),
            $data instanceof MaintenanceEntryData => implode('|', [
                'maintenance',
                $data->performedOn->format('Y-m-d'),
                $data->category->value,
                $lower($data->title),
                Decimal::trim($data->cost),
            ]),
            $data instanceof ComplianceDocumentData => implode('|', [
                'document',
                $data->type->value,
                $lower($data->reference),
                $data->startOn?->format('Y-m-d') ?? '',
                $data->expiryOn?->format('Y-m-d') ?? '',
            ]),
            $data instanceof ExpenseEntryData => implode('|', [
                'expense',
                $data->spentOn->format('Y-m-d'),
                $data->category->value,
                Decimal::trim($data->amount),
                $lower($data->note),
            ]),
            // Phase 22: the same day, places and whole distance (spec.md §7.13).
            $data instanceof TripData => implode('|', [
                'trip',
                $data->travelledOn->format('Y-m-d'),
                $lower($data->fromPlace),
                $lower($data->toPlace),
                Decimal::trim($data->distanceKm),
            ]),
            default => throw new LogicException('Unexpected entry data.'),
        };
    }
}
