<?php

/**
 * A stand-in for Tesseract in the OCR tests (Phase 53.1): no real OCR. Started through
 * `fake-tesseract` (sh) or `fake-tesseract.cmd` (Windows), so the same logic runs on every OS
 * (Phase 54.19, K212, #636).
 *
 * FAKE_TESSERACT_MODE: ok (default) | sleep | fail | empty | broken (exits 127 for everything,
 * as a binary missing a shared library does).
 * FAKE_TESSERACT_TSV: a TSV file to answer with instead of receipt.tsv.
 * FAKE_TESSERACT_ARGS: a file each argument is written to, one per line, then OMP_THREAD_LIMIT.
 */

declare(strict_types=1);

$args = array_slice($argv, 1);
$mode = (string) getenv('FAKE_TESSERACT_MODE');
$mode = $mode === '' ? 'ok' : $mode;

$argsFile = (string) getenv('FAKE_TESSERACT_ARGS');
if ($argsFile !== '') {
    $lines = '';
    foreach ($args as $arg) {
        $lines .= $arg . "\n";
    }
    file_put_contents($argsFile, $lines . 'OMP_THREAD_LIMIT=' . (string) getenv('OMP_THREAD_LIMIT') . "\n");
}

if ($mode === 'broken') {
    fwrite(STDERR, "tesseract: error while loading shared libraries: libleptonica.so.6\n");
    exit(127);
}

switch ($args[0] ?? '') {
    case '--version':
        echo "tesseract 5.3.0\n leptonica-1.82.0\n";
        exit(0);
    case '--list-langs':
        echo "List of available languages in \"/usr/share/tesseract-ocr/5/tessdata/\" (3):\ndeu\neng\nosd\n";
        exit(0);
}

switch ($mode) {
    case 'sleep':
        sleep(5);
        exit(0);
    case 'fail':
        fwrite(STDERR, "Error in pixReadStream\n");
        exit(1);
    case 'empty':
        exit(0);
}

// Tesseract writes its TSV to "<output base>.tsv", the output base being its second argument.
$tsv = (string) getenv('FAKE_TESSERACT_TSV');
$source = $tsv !== '' ? $tsv : __DIR__ . '/receipt.tsv';
exit(isset($args[1]) && @copy($source, $args[1] . '.tsv') ? 0 : 1);
