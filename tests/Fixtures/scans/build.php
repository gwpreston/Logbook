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
if (!is_array($manifest)) {
    throw new RuntimeException('manifest.php must return an array.');
}
foreach (glob(__DIR__ . '/[0-9][0-9]-*') ?: [] as $old) {
    unlink($old);
}
foreach ($manifest as $name => $fixture) {
    if (!is_array($fixture) || !is_string($fixture['type'] ?? null) || !is_array($fixture['lines'] ?? null)) {
        throw new RuntimeException(sprintf('Fixture %s needs a type and lines.', (string) $name));
    }
    $lines = array_values(array_filter($fixture['lines'], is_string(...)));
    [$bytes, $extension] = match ($fixture['type']) {
        'text_pdf' => [ScanFiles::textPdf($lines), 'pdf'],
        'scan_pdf' => [ScanFiles::scannedPdf($lines), 'pdf'],
        'photo' => [ScanFiles::photo($lines), 'jpg'],
        default => throw new RuntimeException(sprintf('Fixture %s: unknown type.', (string) $name)),
    };
    $file = $name . '.' . $extension;
    file_put_contents(__DIR__ . '/' . $file, $bytes);
    $expected = [
        'file' => $file,
        'type' => $fixture['type'],
        'locale' => $fixture['locale'] ?? 'en_GB',
        'chosen' => $fixture['chosen'] ?? null,
        'pick' => $fixture['pick'] ?? null,
        'reply' => $fixture['reply'] ?? [],
        'expect' => $fixture['expect'] ?? [],
    ];
    file_put_contents(
        __DIR__ . '/' . $name . '.json',
        json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
    );
    echo $file, "\n";
}
