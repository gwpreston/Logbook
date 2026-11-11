<?php

declare(strict_types=1);

/*
 * Empty the compiled Twig templates and translation catalogues, so pages
 * show the templates and strings of the code now installed (spec.md §10,
 * K188, #610–#612). In production neither cache checks its source files,
 * so after an upgrade they keep serving the old release until emptied.
 *
 *   php bin/clear-cache.php                     # var/cache under this checkout
 *   php bin/clear-cache.php --cache-dir=<path>  # another cache directory (tests)
 *
 * `composer install` and `composer update` run it (post-install-cmd,
 * post-update-cmd). It loads no .env, container or database, so it works
 * before an install is configured. Only twig/ and translations/ are emptied
 * (#611): an import's staged files, the scheduler lock and the SSO caches
 * are left alone. It never fails an install (#612): any problem is a
 * warning on stderr and the exit code is still 0.
 */

// The command line, as strings (the $argv global is not guaranteed to be set).
$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));

$root = dirname(__DIR__);
// The path AppSettings gives cacheDir, worked out here without loading it.
$cacheDir = $root . '/var/cache';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--cache-dir=')) {
        $cacheDir = rtrim(substr($arg, strlen('--cache-dir=')), '/\\');
    }
}

/**
 * Delete everything inside $dir, keeping $dir itself. True when it is empty
 * afterwards (or never existed).
 */
$emptyDirectory = static function (string $dir): bool {
    if (!is_dir($dir)) {
        // A fresh install or the Docker build has no cache yet.
        return true;
    }

    $ok = true;
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        if (!$entry instanceof SplFileInfo) {
            continue;
        }
        $path = $entry->getPathname();
        // @: a file owned by the web server user fails here; it is reported once below.
        $ok = ($entry->isDir() && !$entry->isLink() ? @rmdir($path) : @unlink($path)) && $ok;
    }

    return $ok;
};

foreach (['twig', 'translations'] as $name) {
    $dir = $cacheDir . '/' . $name;
    try {
        $cleared = $emptyDirectory($dir);
    } catch (Throwable) {
        // An unreadable directory, or the cache path is not a directory.
        $cleared = false;
    }
    if (!$cleared) {
        // Named relative to the checkout, as the message in #612 does.
        $shown = str_starts_with($dir, $root . '/') ? substr($dir, strlen($root) + 1) : $dir;
        fwrite(STDERR, "Could not clear {$shown}: delete it by hand or run bin/clear-cache.php as the web server user.\n");
    }
}

exit(0);
