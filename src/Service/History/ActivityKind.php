<?php

declare(strict_types=1);

namespace Logbook\Service\History;

use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Feature\Feature;
use Logbook\Repository\DatedSource;

/**
 * What a line of the activity feed is (spec.md §7.16), where it is edited,
 * whose files it has and which module it belongs to. Milestones come from
 * the vehicle itself (first registered, bought, sold). A valuation is not a
 * cost: its amount is a price, like a milestone's (spec.md §7.16).
 */
enum ActivityKind: string
{
    case Fuel = 'fuel';
    case Odometer = 'odometer';
    case Maintenance = 'maintenance';
    case Document = 'document';
    case Expense = 'expense';
    case Tyre = 'tyre';
    case Valuation = 'valuation';
    /**
     * Trips (Phase 22) are listed under their own chip only, never in
     * entries(): not under *Everything*, in *Recent activity*, the print
     * view or the sale pack (spec.md §7.22).
     */
    case Trip = 'trip';
    /** Incidents (Phase 27.1): under *Everything*, their chip and *Recent activity*; in print only when ticked. */
    case Incident = 'incident';
    /**
     * Issues (Phase 40.1, spec.md §7.37): noticed on its date and, once
     * fixed, fixed on its date with what fixed it. Updates are not listed;
     * print shows fixed ones only.
     */
    case IssueNoticed = 'issue_noticed';
    case IssueFixed = 'issue_fixed';
    /**
     * A DVSA MOT test (Phase 41, spec.md §7.38): under *Documents*, unless it
     * became an `inspection` document, whose line carries it. Never in print
     * or the sale pack's history, which summarise the tests themselves.
     */
    case MotTest = 'mot_test';
    case Milestone = 'milestone';

    /**
     * Every kind logged as an entry (everything but milestones).
     *
     * @return list<self>
     */
    public static function entries(): array
    {
        return [
            self::Fuel,
            self::Odometer,
            self::Maintenance,
            self::Document,
            self::Expense,
            self::Tyre,
            self::Valuation,
            self::Incident,
            self::IssueNoticed,
            self::IssueFixed,
            self::MotTest,
        ];
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
            self::Valuation => ['valuations.edit', 'entry'],
            self::Trip => ['trips.edit', 'entry'],
            // The incident page, where its photos and linked records are.
            self::Incident => ['incidents.show', 'incident'],
            self::IssueNoticed, self::IssueFixed => ['issues.show', 'issue'],
            // The vehicle's MOT history page, which anyone who can view it may see.
            self::MotTest => ['mot_history.show', null],
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
            self::Trip => Feature::Trips,
            self::Incident => Feature::Incidents,
            self::IssueNoticed, self::IssueFixed => Feature::Issues,
            self::MotTest => Feature::Compliance,
            self::Odometer, self::Expense, self::Valuation, self::Milestone => null,
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
            self::Valuation => AttachmentOwner::Valuation,
            self::Trip => AttachmentOwner::Trip,
            self::Incident => AttachmentOwner::Incident,
            self::IssueNoticed => AttachmentOwner::Issue,
            // Its files are counted once, on the noticed line.
            self::Tyre, self::Milestone, self::IssueFixed, self::MotTest => null,
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
            self::Valuation => DatedSource::Valuation,
            self::Trip => DatedSource::Trip,
            self::Incident => DatedSource::Incident,
            self::IssueNoticed => DatedSource::IssueNoticed,
            self::IssueFixed => DatedSource::IssueFixed,
            self::Document, self::Milestone, self::MotTest => null,
        };
    }

    /**
     * Colour token of the icon (as the expense groups use them).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Fuel => 'c-fuel',
            self::Odometer, self::Valuation, self::Milestone, self::Trip => 'muted',
            self::Maintenance, self::Tyre => 'c-maint',
            self::Document, self::MotTest => 'c-ins',
            self::Expense, self::Incident, self::IssueNoticed, self::IssueFixed => 'c-other',
        };
    }
}
