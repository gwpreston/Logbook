<?php

declare(strict_types=1);

namespace Logbook\Service\Expense;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Expense\CostSource;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Finance\FinanceLine;
use Logbook\Service\Finance\FinanceLineKind;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;

/**
 * One line of the cost ledger (spec.md §7.7): what was paid, when (a calendar
 * date in the owner's time zone) and where it came from. Built from the source
 * entries on every read, never stored.
 */
final readonly class CostItem
{
    public function __construct(
        public Vehicle $vehicle,
        /** Calendar date (midnight UTC; see Support\Date\LocalTime). */
        public DateTimeImmutable $date,
        public CostSource $source,
        public int $sourceId,
        public Money $amount,
        /** Translation key naming the kind of cost, e.g. "maintenance.category.tyres". */
        public string $kindKey,
        public string $icon,
        /** The entry's own words: a station, a maintenance title, a note. */
        public ?string $title = null,
        /** Litres or kWh bought (fuel only), canonical decimal. */
        public ?string $quantity = null,
        public bool $electric = false,
        /** For a fill-up: its fuel and grade, for the grade badge. */
        public ?Fuel $fuel = null,
        public ?FuelGrade $grade = null,
        /** Who added the source entry (Phase 19); null = a former user. */
        public ?int $createdBy = null,
        /** The incident the source record is part of (Phase 27.1, spec.md §7.29). */
        public ?int $incidentId = null,
        /**
         * A document's expiry when it has both a start and an expiry date
         * on or after it: the last day of its cover, so the cost can be
         * spread over it (spec.md §7.35, #153).
         */
        public ?DateTimeImmutable $coverTo = null,
        /** For a line derived from a finance agreement: what it is (spec.md §7.32 *Costs*). */
        public ?FinanceLineKind $financeKind = null,
        /** The finance line is a lease's: never a credit charge (spec.md §7.7, #186). */
        public bool $fromLease = false,
    ) {
    }

    /**
     * A fill-up, dated on the day it happened where the owner lives: a fill
     * at 00:30 BST on 1 April is an April cost, although it is 31 March in UTC.
     */
    public static function fromFuel(FuelEntry $entry, Vehicle $vehicle, string $currency, DateTimeZone $zone): self
    {
        $data = $entry->data;
        $electric = $data->fuel->isElectric();

        return new self(
            vehicle: $vehicle,
            date: self::localDate($data->filledAt, $zone),
            source: CostSource::Fuel,
            sourceId: $entry->id,
            amount: Money::of($data->totalCost, $currency),
            kindKey: 'fuel.fuel.' . $data->fuel->value,
            icon: $electric ? 'ev_station' : 'local_gas_station',
            title: $data->station,
            quantity: $data->volume,
            electric: $electric,
            fuel: $data->fuel,
            grade: $data->grade,
            createdBy: $entry->createdBy,
        );
    }

    /**
     * Maintenance with a cost; free work (cost 0) is history, not an expense.
     */
    public static function fromMaintenance(MaintenanceEntry $entry, Vehicle $vehicle, string $currency): ?self
    {
        $amount = Money::of($entry->data->cost, $currency);
        if ($amount->isZero()) {
            return null;
        }

        return new self(
            vehicle: $vehicle,
            date: $entry->data->performedOn,
            source: CostSource::Maintenance,
            sourceId: $entry->id,
            amount: $amount,
            kindKey: 'maintenance.category.' . $entry->data->category->value,
            icon: $entry->data->category->icon(),
            title: $entry->data->title,
            createdBy: $entry->createdBy,
            incidentId: $entry->incidentId,
        );
    }

    /**
     * A document with a cost, dated by its start date, else the day it was
     * added (in the owner's time zone).
     */
    public static function fromCompliance(
        ComplianceDocument $document,
        Vehicle $vehicle,
        string $currency,
        DateTimeZone $zone,
    ): ?self {
        $data = $document->data;
        $amount = Money::of($data->cost, $currency);
        if ($amount->isZero()) {
            return null;
        }

        return new self(
            vehicle: $vehicle,
            date: $data->startOn ?? self::localDate($document->createdAt, $zone),
            source: CostSource::Compliance,
            sourceId: $document->id,
            amount: $amount,
            kindKey: 'compliance.type.' . $data->type->value,
            icon: $data->type->icon(),
            title: $data->title ?? $data->provider,
            createdBy: $document->createdBy,
            coverTo: $data->startOn !== null && $data->expiryOn !== null && $data->expiryOn >= $data->startOn
                ? $data->expiryOn
                : null,
        );
    }

    /**
     * An ad-hoc expense; zero included (the owner logged it on purpose).
     */
    public static function fromExpense(ExpenseEntry $entry, Vehicle $vehicle, string $currency): self
    {
        return new self(
            vehicle: $vehicle,
            date: $entry->data->spentOn,
            source: CostSource::Expense,
            sourceId: $entry->id,
            amount: Money::of($entry->data->amount, $currency),
            kindKey: 'expense.category.' . $entry->data->category->value,
            icon: $entry->data->category->icon(),
            title: $entry->data->note,
            createdBy: $entry->createdBy,
            incidentId: $entry->incidentId,
        );
    }

    /**
     * A line derived from a finance agreement (spec.md §7.32 *Costs*): a
     * credit charge or a rental, shown and counted as a *Finance and lease*
     * expense, with no title of its own (#125).
     */
    public static function fromFinance(Vehicle $vehicle, FinanceAgreement $agreement, FinanceLine $line, string $currency): self
    {
        return new self(
            vehicle: $vehicle,
            date: $line->date,
            source: CostSource::Finance,
            sourceId: $agreement->id,
            amount: Money::of($line->amount, $currency),
            kindKey: 'expense.category.' . ExpenseCategory::Finance->value,
            icon: ExpenseCategory::Finance->icon(),
            financeKind: $line->kind,
            fromLease: $agreement->type() === AgreementType::Lease,
        );
    }

    public function group(): CostGroup
    {
        return $this->source->group();
    }

    public function currency(): string
    {
        return $this->amount->currency;
    }

    /**
     * Ledger order: by date, then fuel / maintenance / documents / other,
     * then as logged.
     */
    public static function compare(self $a, self $b): int
    {
        $sources = CostSource::cases();

        return ($a->date <=> $b->date)
            ?: (array_search($a->source, $sources, true) <=> array_search($b->source, $sources, true))
            ?: $a->sourceId <=> $b->sourceId;
    }

    private static function localDate(DateTimeImmutable $instant, DateTimeZone $zone): DateTimeImmutable
    {
        $date = LocalTime::parseDate(LocalTime::fromUtc($instant, $zone)->format('Y-m-d'));
        assert($date instanceof DateTimeImmutable);

        return $date;
    }
}
