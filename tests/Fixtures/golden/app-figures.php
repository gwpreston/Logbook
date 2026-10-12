<?php

/**
 * Prints the app's own figure for every row of expected.csv, as a CSV of
 * `id,actual` on stdout, for comparing with the spreadsheet:
 *
 *     php tests/Fixtures/golden/app-figures.php > app-figures.csv
 *
 * It runs the golden test on SQLite (the test database in var/) and has it
 * write the figures; it never writes expected.csv. Join the two on `id`.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$out = tempnam(sys_get_temp_dir(), 'golden-figures-');
if ($out === false) {
    fwrite(STDERR, "Cannot make a temporary file\n");
    exit(1);
}
$command = sprintf(
    'GOLDEN_APP_FIGURES=%s %s %s --filter testEveryFigureCanBeRead tests/Integration/Golden/GoldenHouseholdTest.php 1>&2',
    escapeshellarg($out),
    escapeshellarg(PHP_BINARY),
    escapeshellarg($root . '/vendor/bin/phpunit'),
);
chdir($root);
passthru($command, $status);
if ($status === 0 && is_file($out)) {
    readfile($out);
}
@unlink($out);
exit($status);
