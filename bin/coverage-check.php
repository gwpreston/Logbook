<?php

declare(strict_types=1);

/*
 * Fail when overall line coverage of src/ drops below the committed floor.
 *
 *   composer test:coverage                         # writes var/coverage/clover.xml
 *   php bin/coverage-check.php                     # checks it against tests/coverage-floor.txt
 *   php bin/coverage-check.php <clover.xml> [<floor file>]
 *
 * The floor is a whole percentage, never below 80. It only moves up: when
 * coverage is a full point or more above it, the script says so and the floor
 * should be raised in the same change. Lines changed by a pull request are
 * checked separately in CI (diff-cover, 80%).
 */

$root = dirname(__DIR__);
$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));
$clover = $args[1] ?? $root . '/var/coverage/clover.xml';
$floorFile = $args[2] ?? $root . '/tests/coverage-floor.txt';

$floorText = @file_get_contents($floorFile);
if ($floorText === false || preg_match('/^\s*(\d{1,3})\s*$/', $floorText, $m) !== 1) {
    fwrite(STDERR, "Cannot read a whole percentage from {$floorFile}\n");
    exit(2);
}
$floor = (int) $m[1];
if ($floor < 80 || $floor > 100) {
    fwrite(STDERR, "The coverage floor must be between 80 and 100, not {$floor}\n");
    exit(2);
}

$xml = @simplexml_load_file($clover);
if ($xml === false) {
    fwrite(STDERR, "Cannot read {$clover}; run composer test:coverage first\n");
    exit(2);
}
$metrics = $xml->project->metrics ?? null;
$statements = (int) ($metrics['statements'] ?? 0);
$covered = (int) ($metrics['coveredstatements'] ?? 0);
if ($statements === 0) {
    fwrite(STDERR, "{$clover} has no statements; was coverage collected?\n");
    exit(2);
}

$percent = 100 * $covered / $statements;
printf("Line coverage: %.2f%% (%d/%d lines); floor %d%%\n", $percent, $covered, $statements, $floor);

if ($percent < $floor) {
    fwrite(STDERR, sprintf("Coverage is below the %d%% floor. Add tests for the new code.\n", $floor));
    exit(1);
}
if (floor($percent) > $floor) {
    printf("Coverage is above the floor: raise %s to %d.\n", 'tests/coverage-floor.txt', (int) floor($percent));
}
