<?php

declare(strict_types=1);

namespace Logbook\Service\Import;

use BackedEnum;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Service\Export\ExportModule;

/**
 * One field an import can fill (spec.md §7.13): the module form's field
 * name, how its text is read, and what a matching CSV header looks like.
 */
final readonly class ImportField
{
    /**
     * @param string $key the form field it fills
     * @param string $exportKey the CSV export's column header for it (translation key)
     * @param list<string> $aliases other English header names it answers to
     * @param class-string<BackedEnum>|null $enum for Choice / Source fields
     * @param string|null $labelPrefix translation key prefix of the enum's labels
     * @param string|null $default the value used when the column is missing or blank
     */
    public function __construct(
        public string $key,
        public FieldKind $kind,
        public string $exportKey,
        public bool $required = false,
        public array $aliases = [],
        public ?string $enum = null,
        public ?string $labelPrefix = null,
        public ?string $default = null,
    ) {
    }

    /**
     * Translation key of the field's name on the mapping page.
     */
    public function labelKey(): string
    {
        return 'import.field.' . $this->key;
    }

    /**
     * The fields of a module, in the order of its CSV export.
     *
     * @return list<self>
     */
    public static function forModule(ExportModule $module): array
    {
        $currency = new self('currency', FieldKind::Currency, 'export.column.currency');

        return match ($module) {
            ExportModule::Fuel => [
                new self(
                    'filled_at',
                    FieldKind::DateTime,
                    'export.column.date_time',
                    true,
                    ['date', 'datetime', 'time', 'filled at'],
                ),
                new self('odometer', FieldKind::Distance, 'export.column.odometer', true, ['mileage', 'odo', 'km', 'miles']),
                new self('fuel', FieldKind::Choice, 'export.column.fuel', false, ['fuel type'], Fuel::class, 'fuel.fuel.'),
                new self('grade', FieldKind::Grade, 'export.column.grade_code', false, ['fuel grade', 'grade code']),
                new self(
                    'volume',
                    FieldKind::Volume,
                    'export.column.volume',
                    false,
                    ['litres', 'liters', 'quantity', 'gallons', 'kwh', 'energy'],
                ),
                new self('unit', FieldKind::VolumeUnit, 'export.column.unit', false, ['volume unit']),
                new self(
                    'price',
                    FieldKind::UnitPrice,
                    'export.column.price_per_unit',
                    false,
                    ['price', 'unit price', 'price per litre', 'price per liter', 'price per gallon'],
                ),
                new self('total', FieldKind::Number, 'export.column.total', false, ['cost', 'total cost', 'amount']),
                $currency,
                new self('partial', FieldKind::Flag, 'export.column.partial', false, ['partial fill', 'partial fill-up']),
                new self('missed_previous', FieldKind::Flag, 'export.column.missed_previous', false, ['missed']),
                new self(
                    'station',
                    FieldKind::Text,
                    'export.column.station',
                    false,
                    ['location', 'petrol station', 'gas station'],
                ),
                new self('notes', FieldKind::Text, 'export.column.notes', false, ['note', 'comment', 'comments']),
            ],
            ExportModule::Odometer => [
                new self('recorded_at', FieldKind::DateTime, 'export.column.date_time', true, ['date', 'datetime', 'time']),
                new self('reading', FieldKind::Distance, 'export.column.odometer', true, ['reading', 'mileage', 'km', 'miles']),
                new self(
                    'source',
                    FieldKind::Source,
                    'export.column.source',
                    false,
                    [],
                    OdometerSource::class,
                    'odometer.source.',
                ),
                new self('note', FieldKind::Text, 'export.column.note', false, ['notes', 'comment']),
            ],
            ExportModule::Maintenance => [
                new self('performed_on', FieldKind::Date, 'export.column.date', true, ['performed on', 'done on']),
                new self(
                    'category',
                    FieldKind::Choice,
                    'export.column.category',
                    false,
                    ['type'],
                    MaintenanceCategory::class,
                    'maintenance.category.',
                    'other',
                ),
                new self('title', FieldKind::Text, 'export.column.title', true, ['work', 'service', 'job']),
                new self('odometer', FieldKind::Distance, 'export.column.odometer', false, ['mileage', 'odo']),
                new self('cost', FieldKind::Number, 'export.column.cost', false, ['amount', 'price', 'total']),
                $currency,
                new self('vendor', FieldKind::Text, 'export.column.vendor', false, ['vendor', 'shop', 'workshop', 'mechanic']),
                new self('description', FieldKind::Text, 'export.column.details', false, ['description', 'notes']),
            ],
            ExportModule::Documents => [
                new self(
                    'type',
                    FieldKind::Choice,
                    'export.column.type',
                    true,
                    ['document'],
                    ComplianceType::class,
                    'compliance.type.',
                ),
                new self('title', FieldKind::Text, 'export.column.title', false, ['name']),
                new self('provider', FieldKind::Text, 'export.column.provider', false, ['insurer', 'company']),
                new self(
                    'reference',
                    FieldKind::Text,
                    'export.column.reference',
                    false,
                    ['policy number', 'certificate number', 'number'],
                ),
                new self('start_on', FieldKind::Date, 'export.column.start', false, ['start date', 'valid from', 'from']),
                new self(
                    'expiry_on',
                    FieldKind::Date,
                    'export.column.expiry',
                    false,
                    ['expiry date', 'expires', 'valid until', 'until', 'end'],
                ),
                new self('odometer', FieldKind::Distance, 'export.column.odometer', false, ['mileage', 'odo']),
                new self('cost', FieldKind::Number, 'export.column.cost', false, ['amount', 'price']),
                $currency,
                new self('notes', FieldKind::Text, 'export.column.notes', false, ['note', 'comment']),
            ],
            ExportModule::Expenses => [
                new self('spent_on', FieldKind::Date, 'export.column.date', true, ['spent on']),
                new self(
                    'category',
                    FieldKind::Choice,
                    'export.column.category',
                    false,
                    ['type'],
                    ExpenseCategory::class,
                    'expense.category.',
                    'other',
                ),
                new self('amount', FieldKind::Number, 'export.column.amount', false, ['cost', 'total', 'price']),
                $currency,
                new self('note', FieldKind::Text, 'export.column.note', false, ['notes', 'description', 'comment']),
            ],
            ExportModule::Trips => [
                new self('travelled_on', FieldKind::Date, 'export.column.date', true, ['travelled on', 'day']),
                new self('from_place', FieldKind::Text, 'export.column.from', true, ['start', 'origin']),
                new self('to_place', FieldKind::Text, 'export.column.to', true, ['destination', 'end']),
                new self(
                    'is_return',
                    FieldKind::Flag,
                    'export.column.return',
                    false,
                    ['return journey', 'round trip'],
                    null,
                    null,
                    'no',
                ),
                new self('distance', FieldKind::Distance, 'export.column.distance', false, ['miles', 'kilometres', 'km']),
                new self('odometer_start', FieldKind::Distance, 'export.column.odometer_start', false, ['start odometer']),
                new self('odometer_end', FieldKind::Distance, 'export.column.odometer_end', false, ['end odometer']),
                new self('is_business', FieldKind::Flag, 'export.column.business', false, ['business trip'], null, null, 'yes'),
                new self('purpose', FieldKind::Text, 'export.column.purpose', false, ['reason', 'description']),
                new self('passengers', FieldKind::Number, 'export.column.passengers', false, []),
                new self('notes', FieldKind::Text, 'export.column.notes', false, ['note', 'comment']),
            ],
            // Export only (spec.md §7.17).
            ExportModule::Tyres,
            ExportModule::TyreChanges,
            ExportModule::Valuations,
            ExportModule::Incidents,
            ExportModule::Issues,
            ExportModule::Finance => [],
        };
    }
}
