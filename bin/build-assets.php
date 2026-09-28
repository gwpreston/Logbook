<?php

declare(strict_types=1);

/*
 * Build static assets without Node: copy assets/ into public/assets and write
 * public/assets/manifest.json (content hashes used for cache busting).
 *
 *   php bin/build-assets.php            # build
 *   php bin/build-assets.php --check    # exit 1 if public/assets is stale (CI)
 *
 * Third-party libraries live pre-built in assets/vendor (see package.json for
 * how they are refreshed), so a bare-PHP install never needs Node.
 */

$root = dirname(__DIR__);
$source = $root . '/assets';
$target = $root . '/public/assets';
// The command line, as strings (the $argv global is not guaranteed to be set).
$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));
$check = in_array('--check', $args, true);

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && !str_starts_with($file->getFilename(), '.')) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
        $files[$relative] = $file->getPathname();
    }
}
ksort($files);

$manifest = [];
$stale = [];
foreach ($files as $relative => $path) {
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, "Cannot read {$path}\n");
        exit(1);
    }
    $manifest[$relative] = substr(hash('sha256', $contents), 0, 12);

    $destination = $target . '/' . $relative;
    if (!is_file($destination) || hash_file('sha256', $destination) !== hash('sha256', $contents)) {
        $stale[] = $relative;
        if (!$check) {
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0775, true);
            }
            file_put_contents($destination, $contents);
        }
    }
}

$manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
$manifestFile = $target . '/manifest.json';
if (!is_file($manifestFile) || file_get_contents($manifestFile) !== $manifestJson) {
    $stale[] = 'manifest.json';
    if (!$check) {
        file_put_contents($manifestFile, $manifestJson);
    }
}

if ($check) {
    if ($stale !== []) {
        fwrite(STDERR, "public/assets is out of date; run `composer build-assets` and commit:\n  ");
        fwrite(STDERR, implode("\n  ", $stale) . "\n");
        exit(1);
    }
    fwrite(STDOUT, "public/assets is up to date.\n");
    exit(0);
}

fwrite(STDOUT, sprintf("Built %d asset(s) into public/assets (%d changed).\n", count($files), count($stale)));
