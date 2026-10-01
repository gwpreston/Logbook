<?php

declare(strict_types=1);

/*
 * Writes the scan fixture set (manifest.php) into this directory: each
 * document as a text PDF, a scanned PDF or a phone photo, and beside it
 * the reply a model gives and what the form should hold, as JSON.
 *
 *     php tests/Fixtures/scans/build.php
 *
 * The files are committed; run this after changing the manifest.
 */

use Logbook\Tests\Support\ScanFiles;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$manifest = require __DIR__ . '/manifest.php';
foreach (glob(__DIR__ . '/[0-9][0-9]-*') ?: [] as $old) {
    unlink($old);
}
foreach ($manifest as $name => $fixture) {
    [$bytes, $extension] = match ($fixture['type']) {
        'text_pdf' => [ScanFiles::textPdf($fixture['lines']), 'pdf'],
        'scan_pdf' => [ScanFiles::scannedPdf($fixture['lines']), 'pdf'],
        'photo' => [ScanFiles::photo($fixture['lines']), 'jpg'],
    };
    file_put_contents(__DIR__ . '/' . $name . '.' . $extension, $bytes);
    $expected = [
        'file' => $name . '.' . $extension,
        'type' => $fixture['type'],
        'locale' => $fixture['locale'] ?? 'en_GB',
        'chosen' => $fixture['chosen'] ?? null,
        'pick' => $fixture['pick'] ?? null,
        'reply' => $fixture['reply'],
        'expect' => $fixture['expect'],
    ];
    file_put_contents(
        __DIR__ . '/' . $name . '.json',
        json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
    );
    echo $name, '.', $extension, "\n";
}
