<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

/**
 * What importing from another app does with one row (spec.md §7.13
 * *Preview*): the CSV importer's outcomes, plus a known origin and the
 * rows this import deliberately leaves out.
 */
enum AppRowStatus: string
{
    case Import = 'import';
    /** Fails the form's validation; listed with its errors. */
    case Invalid = 'invalid';
    /** The same entry exists already, or earlier in the file. */
    case Duplicate = 'duplicate';
    /** Its id came across in an earlier import into this vehicle. */
    case AlreadyImported = 'already_imported';
    /** Left out on purpose (income, a template, a fuel or category set to *Don't import*), with the reason. */
    case NotImported = 'not_imported';
}
