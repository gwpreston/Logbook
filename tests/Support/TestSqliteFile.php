<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

/**
 * Every SQLite test run starts from a fresh file (spec.md §9 *Test suite
 * only*). On Windows a file another process still has open (a leftover
 * bin/ script, or a run in the dev container) can't be deleted, and a run
 * that carried on would test on the old file. So the bootstrap stops at
 * once with the message this returns (K206, #626).
 */
final class TestSqliteFile
{
    /**
     * Delete $file if it exists; null when the run can go on, else the
     * message to stop with, naming the file as the run's settings give it.
     *
     * @param (callable(string): bool)|null $delete replaced in a test; by
     *     default self::replace()
     */
    public static function delete(string $file, ?callable $delete = null): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $delete ??= self::replace(...);
        if ($delete($file)) {
            return null;
        }

        return "Can't delete {$file}: another process has it open. Close it (a leftover bin/ script"
            . ' or a run in the dev container) and run the tests again.';
    }

    /**
     * Delete $path and create it again, empty (a fresh SQLite database).
     * On Windows, deleting a file another PHP process has open succeeds
     * but leaves it pending until that process closes it, and nothing can
     * be created under its name meanwhile; only the create shows that.
     * Warnings are silenced, so the refusal is the only line printed.
     */
    private static function replace(string $path): bool
    {
        if (!@unlink($path)) {
            return false;
        }
        $handle = @fopen($path, 'x');

        return $handle !== false && fclose($handle);
    }
}
