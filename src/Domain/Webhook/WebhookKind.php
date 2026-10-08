<?php

declare(strict_types=1);

namespace Logbook\Domain\Webhook;

/**
 * What a webhook delivery is about (spec.md §7.20 *Webhooks*, #290, #301):
 * the history feed's kinds where it has one, and the rest of what the API
 * writes on a vehicle. Each names the API paths a receiver fetches it at.
 */
enum WebhookKind: string
{
    case Fuel = 'fuel';
    case Odometer = 'odometer';
    case Maintenance = 'maintenance';
    case Document = 'document';
    case Expense = 'expense';
    /** A tyre change (the history feed's `tyre`). */
    case Tyre = 'tyre';
    case Valuation = 'valuation';
    case Trip = 'trip';
    case Incident = 'incident';
    case TreadCheck = 'tread_check';
    /** A tyre's own details; `entry_id` is the tyre. */
    case TyreDetails = 'tyre_details';
    case Schedule = 'schedule';
    /** An agreement; its payments, quotes and *End* are updates of it. */
    case Finance = 'finance';
    /** The vehicle itself; `entry_id` is the vehicle. */
    case Vehicle = 'vehicle';
    /** `reminder.changed` only. */
    case Reminder = 'reminder';

    /**
     * The API path that returns this one entry, when the API has one
     * (relative to `/api/v1`, as every link).
     */
    public function entryLink(int $vehicleId, int $entryId): ?string
    {
        $base = '/vehicles/' . $vehicleId;
        $list = match ($this) {
            self::Fuel => 'fuel',
            self::Odometer => 'odometer',
            self::Maintenance => 'maintenance',
            self::Document => 'documents',
            self::Expense => 'expenses',
            self::Valuation => 'valuations',
            self::Trip => 'trips',
            self::Incident => 'incidents',
            self::Schedule => 'schedules',
            self::Vehicle => '',
            self::Tyre, self::TreadCheck, self::TyreDetails, self::Finance, self::Reminder => null,
        };

        return match ($list) {
            null => null,
            '' => $base,
            default => $base . '/' . $list . '/' . $entryId,
        };
    }

    /**
     * The API list it appears in.
     */
    public function listLink(int $vehicleId): string
    {
        $base = '/vehicles/' . $vehicleId;

        return match ($this) {
            self::Fuel => $base . '/fuel',
            self::Odometer => $base . '/odometer',
            self::Maintenance => $base . '/maintenance',
            self::Document => $base . '/documents',
            self::Expense => $base . '/expenses',
            self::Valuation => $base . '/valuations',
            self::Trip => $base . '/trips',
            self::Incident => $base . '/incidents',
            self::Schedule => $base . '/schedules',
            self::Tyre => $base . '/tyres/changes',
            self::TreadCheck, self::TyreDetails => $base . '/tyres',
            self::Finance => $base . '/finance/agreements',
            self::Vehicle => '/vehicles',
            self::Reminder => '/reminders?vehicle=' . $vehicleId,
        };
    }
}
