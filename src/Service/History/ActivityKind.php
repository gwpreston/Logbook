<?php

declare(strict_types=1);

namespace Logbook\Service\History;

use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Feature\Feature;
use Logbook\Repository\DatedSource;

/**
 * What a line of the activity feed is (spec.md §7.16), where it is edited,
 * whose files it has and which module it belongs to. Milestones come from
 * the vehicle itself (first registered, bought, sold).
 */
enum ActivityKind: string
{
    case Fuel = 'fuel';
    case Odometer = 'odometer';
    case Maintenance = 'maintenance';
    case Document = 'document';
    case Expense = 'expense';
    case Tyre = 'tyre';
    case Milestone = 'milestone';

    /**
     * Every kind logged as an entry (everything but milestones).
     *
     * @return list<self>
     */
    public static function entries(): array
    {
        return [self::Fuel, self::Odometer, self::Maintenance, self::Document, self::Expense, self::Tyre];
    }

    /**
     * The edit page (route name) and the name of its entry placeholder;
     * a milestone opens the vehicle's edit page.
     *
     * @return array{0: string, 1: ?string}
     */
    public function editRoute(): array
    {
        return match ($this) {
            self::Fuel => ['fuel.edit', 'entry'],
            self::Odometer => ['odometer.edit', 'reading'],
            self::Maintenance => ['maintenance.edit', 'entry'],
            self::Document => ['compliance.edit', 'document'],
            self::Expense => ['expenses.edit', 'entry'],
            self::Tyre => ['tyres.changes.edit', 'change'],
            self::Milestone => ['vehicles.edit', null],
        };
    }

    /**
     * The module that must be on for this kind to be listed (null: core).
     */
    public function feature(): ?Feature
    {
        return match ($this) {
            self::Fuel => Feature::Fuel,
            self::Maintenance => Feature::Maintenance,
            self::Document => Feature::Compliance,
            self::Tyre => Feature::Tyres,
            self::Odometer, self::Expense, self::Milestone => null,
        };
    }

    /**
     * Whose attachments the line counts (none for a milestone or a tyre
     * change: its receipt is on the linked service record).
     */
    public function filesOwner(): ?AttachmentOwner
    {
        return match ($this) {
            self::Fuel => AttachmentOwner::Fuel,
            self::Odometer => AttachmentOwner::Odometer,
            self::Maintenance => AttachmentOwner::Maintenance,
            self::Document => AttachmentOwner::Compliance,
            self::Expense => AttachmentOwner::Expense,
            self::Tyre, self::Milestone => null,
        };
    }

    /**
     * The table paged by date for this kind (documents and milestones are
     * placed in PHP).
     */
    public function datedSource(): ?DatedSource
    {
        return match ($this) {
            self::Fuel => DatedSource::Fuel,
            self::Odometer => DatedSource::Reading,
            self::Maintenance => DatedSource::Maintenance,
            self::Expense => DatedSource::Expense,
            self::Tyre => DatedSource::TyreChange,
            self::Document, self::Milestone => null,
        };
    }

    /**
     * Colour token of the icon (as the expense groups use them).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Fuel => 'c-fuel',
            self::Odometer, self::Milestone => 'muted',
            self::Maintenance, self::Tyre => 'c-maint',
            self::Document => 'c-ins',
            self::Expense => 'c-other',
        };
    }
}
