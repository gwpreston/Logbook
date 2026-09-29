<?php

declare(strict_types=1);

namespace Logbook\Domain\Attachment;

/**
 * The kinds of entry a file can be attached to (the `owner_type` column).
 * `odometer` is for manual readings only: a derived reading's files are its
 * entry's. `purchase` and `sale` are the vehicle's purchase and sale (the
 * owner id is the vehicle's): paperwork belongs to those events, and there
 * is deliberately no `vehicle` owner (spec.md §7.12). `valuation` is a
 * valuation's (Phase 14.1): the screenshot of a quote.
 */
enum AttachmentOwner: string
{
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Compliance = 'compliance';
    case Expense = 'expense';
    case Odometer = 'odometer';
    case Purchase = 'purchase';
    case Sale = 'sale';
    case Valuation = 'valuation';
}
