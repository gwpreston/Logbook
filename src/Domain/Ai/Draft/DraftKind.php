<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Draft;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;

/**
 * What a draft would add (spec.md §7.26 *Drafting entries*). Each kind is
 * written as its API write and its form write it, and needs what its form
 * needs: its module and an ability on the vehicle.
 */
enum DraftKind: string
{
    case Fuel = 'fuel';
    case Odometer = 'odometer';
    case Maintenance = 'maintenance';
    case Document = 'document';
    case Expense = 'expense';
    case TyreCheck = 'tyre_check';
    case Reminder = 'reminder';
    /** Phase 27.1 (spec.md §7.29). */
    case Incident = 'incident';

    /**
     * The module it needs; null for the core ones.
     */
    public function feature(): ?Feature
    {
        return match ($this) {
            self::Fuel => Feature::Fuel,
            self::Maintenance => Feature::Maintenance,
            self::Document => Feature::Compliance,
            self::TyreCheck => Feature::Tyres,
            self::Reminder => Feature::Reminders,
            self::Incident => Feature::Incidents,
            self::Odometer, self::Expense => null,
        };
    }

    /**
     * The ability its form needs: a manual reminder needs Manage (spec.md §7.21).
     */
    public function ability(): VehicleAbility
    {
        return $this === self::Reminder ? VehicleAbility::Manage : VehicleAbility::Log;
    }

    public function toolName(): string
    {
        return match ($this) {
            self::Fuel => 'draft_fill_up',
            self::Odometer => 'draft_reading',
            self::Maintenance => 'draft_service_record',
            self::Document => 'draft_document',
            self::Expense => 'draft_expense',
            self::TyreCheck => 'draft_tyre_check',
            self::Reminder => 'draft_reminder',
            self::Incident => 'draft_incident',
        };
    }

    public function labelKey(): string
    {
        return 'ask.draft.kind.' . $this->value;
    }
}
