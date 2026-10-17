<?php

declare(strict_types=1);

/*
 * Check the README and the docs (spec.md §10 *README and docs*, Phase 52).
 * Run in CI; needs the dev autoloader (composer install).
 *
 *   php bin/tools/check-docs.php [<repository root>]
 *
 * Fails when a relative link, anchor or image in README.md, CONTRIBUTING.md
 * or docs/**.md doesn't resolve, a guide in docs/ isn't linked from
 * docs/README.md, or an image in docs/images/ isn't used. docs/phases/ is
 * left out.
 *
 * Exit code: 0 fine, 1 problems found.
 */

use Logbook\Tests\Support\DocsCheck;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));
$root = $args[1] ?? dirname(__DIR__, 2);

$check = new DocsCheck();
$problems = $check->problems($root);
if ($problems !== []) {
    fwrite(STDERR, "The docs have problems:\n  " . implode("\n  ", $problems) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("Docs OK: %d Markdown files checked.\n", count($check->files($root))));
exit(0);
