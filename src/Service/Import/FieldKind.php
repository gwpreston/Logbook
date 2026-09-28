<?php

declare(strict_types=1);

namespace Logbook\Service\Import;

/**
 * How an import column's text is read before the module's form parser sees
 * it (spec.md §7.13).
 */
enum FieldKind
{
    /** Passed through; the form parser validates it. */
    case Text;
    /** A plain number or amount; canonical ("1234.5") or the owner's locale format. */
    case Number;
    /** An odometer reading in the file's distance unit. */
    case Distance;
    /** A fill-up's volume in the file's (or the row's) volume unit; kWh for electricity. */
    case Volume;
    /** A price per unit of volume, like Volume. */
    case UnitPrice;
    /** A calendar date in the chosen date order. */
    case Date;
    /** A local date and time (a date alone means noon). */
    case DateTime;
    /** One of an enum's cases: its code or its label (owner's language or English). */
    case Choice;
    /** yes / no. */
    case Flag;
    /** Must match the vehicle's currency: amounts are never converted. */
    case Currency;
    /** A fill-up row's own volume unit. */
    case VolumeUnit;
    /** An odometer reading's source: readings from fill-ups and services are implied. */
    case Source;
}
