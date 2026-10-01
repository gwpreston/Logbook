<?php

declare(strict_types=1);

namespace Logbook\Domain\Access;

/**
 * What a user may do with one vehicle (spec.md §5 *Access policy*). The
 * Phase 19 sharing levels are made of these; a route with `{id}` declares
 * the one it needs.
 */
enum VehicleAbility: string
{
    /** The vehicle, its entries, history and files. */
    case View = 'view';
    /** Amounts, prices, reports, cost of ownership, valuations, CSV exports. */
    case ViewCosts = 'view_costs';
    /** Adding fill-ups, readings, service records, documents, expenses, tyre changes. */
    case Log = 'log';
    /** Editing the vehicle and any entry, schedules, reminders, valuations, import, sale pack. */
    case Manage = 'manage';
    /** Archive, restore, delete, transfer, sharing. */
    case Own = 'own';
    /** Other drivers' trips and their destinations (Phase 22; Manage and Own). */
    case ViewOthersTrips = 'view_others_trips';
    /**
     * An incident's fault, other party, police reference, claim number,
     * payout, estimate and driver (Phase 27.1; Manage and Own, and the
     * incident's creator, spec.md §7.29).
     */
    case ViewIncidentDetails = 'view_incident_details';
}
