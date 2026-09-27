<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in server (`composer start`). Development only.
 *
 * Serves real files from public/ (also under APP_BASE_PATH, so subpath
 * behaviour can be tried locally) and sends everything else to the front
 * controller.
 */

use Logbook\Kernel;

require dirname(__DIR__) . '/vendor/autoload.php';

$publicDir = dirname(__DIR__) . '/public';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = rawurldecode((string) parse_url(is_string($requestUri) ? $requestUri : '/', PHP_URL_PATH));
$basePath = Kernel::settings()->basePath;

if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
    $path = substr($path, strlen($basePath));
}

$file = realpath($publicDir . $path);
if (
    $file !== false
    && is_file($file)
    && str_starts_with($file, (string) realpath($publicDir))
    && pathinfo($file, PATHINFO_EXTENSION) !== 'php'
) {
    $types = [
        'css' => 'text/css', 'js' => 'text/javascript', 'json' => 'application/json',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon',
        'webmanifest' => 'application/manifest+json', 'txt' => 'text/plain', 'woff2' => 'font/woff2',
    ];
    header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($file);

    return true;
}

unset($basePath);
require $publicDir . '/index.php';

return true;
