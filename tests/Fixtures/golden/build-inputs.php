<?php

/**
 * Rewrites the golden household's inputs (tests/Fixtures/golden/inputs/*.csv)
 * from Logbook\Tests\Support\Golden\InputBuilder, and the empty expected.csv
 * skeleton (never over a filled one). Run with
 * `php tests/Fixtures/golden/build-inputs.php`; review the diff, since the
 * owner's spreadsheet starts from these files.
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

(new Logbook\Tests\Support\Golden\InputBuilder())->write(__DIR__ . '/inputs');
(new Logbook\Tests\Support\Golden\ExpectedSkeleton())->write(__DIR__ . '/inputs', __DIR__ . '/expected.csv');
