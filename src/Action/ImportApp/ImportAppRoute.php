<?php

declare(strict_types=1);

namespace Logbook\Action\ImportApp;

/**
 * Shared names of the *Import from another app* pages (spec.md §7.13).
 */
final class ImportAppRoute
{
    /** Session key: the staged file of the import in progress. */
    public const string SESSION = 'import_app';
    /** StagedFiles kind (letters only). */
    public const string STAGE = 'importapp';
    /** StagedFiles kind of Tracktor's uploads ZIP (letters only). */
    public const string FILES_STAGE = 'importappfiles';
    /** Shown wherever a backup ZIP is refused. */
    public const string COMMAND = 'php bin/import-app.php <backup.zip>';
    /** Shown with Tracktor's how-to. */
    public const string TRACKTOR_COMMAND = 'php bin/import-app.php <export.json> --files <folder|zip>';
}
