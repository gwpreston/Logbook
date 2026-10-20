<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

use Logbook\Service\Import\App\Fuelio\FuelioCommand;
use Logbook\Service\Import\App\Tracktor\TracktorCommand;

/**
 * `php bin/import-app.php` (spec.md §7.13 *Web and command line*): a
 * Tracktor JSON export goes to TracktorCommand, anything else (a Fuelio CSV
 * or backup ZIP) to FuelioCommand, each with its own options.
 */
final readonly class ImportAppCommand
{
    public const string USAGE = "Usage: php bin/import-app.php <file.csv|file.zip> [--vehicle <id> | --create]"
        . " [--as <username>] [--schedules] [--skip-invalid] [--dry-run]\n"
        . "       php bin/import-app.php <export.json> [--files <folder|zip>] [--vehicle <id> | --create]"
        . " [--as <username>] [--schedules] [--skip-invalid] [--dry-run]\n";

    public function __construct(private FuelioCommand $fuelio, private TracktorCommand $tracktor)
    {
    }

    /**
     * @param list<string> $args the arguments after the script's name
     * @param resource $out
     * @param resource $err
     * @return int 0 imported (or previewed), 1 refused or failed, 3 usage
     */
    public function run(array $args, $out, $err): int
    {
        $path = null;
        foreach ($args as $index => $arg) {
            $previous = $args[$index - 1] ?? '';
            if (!str_starts_with($arg, '-') && !in_array($previous, ['--vehicle', '--as', '--files'], true)) {
                $path = $arg;
                break;
            }
        }
        if ($path === null) {
            fwrite($err, self::USAGE);

            return 3;
        }

        return self::isJson($path) ? $this->tracktor->run($args, $out, $err) : $this->fuelio->run($args, $out, $err);
    }

    /**
     * Why a run with rows that have problems stopped (K11, K65, K126),
     * naming --skip-invalid only when it would import something.
     */
    public static function invalidRows(int $invalid, int $importable): string
    {
        return sprintf("%d row(s) have problems (listed above), so nothing was written: ", $invalid)
            . ($importable > 0
                ? "fix them, or run again with --skip-invalid to import the other rows.\n"
                : "fix them; no other rows are left to import.\n");
    }

    /**
     * A JSON object, by its first bytes (a byte-order mark and spaces
     * allowed).
     */
    private static function isJson(string $path): bool
    {
        if (!is_file($path)) {
            return str_ends_with(strtolower($path), '.json');
        }
        $head = (string) file_get_contents($path, false, null, 0, 64);
        $head = ltrim(str_starts_with($head, "\u{FEFF}") ? substr($head, 3) : $head);

        return str_starts_with($head, '{');
    }
}
