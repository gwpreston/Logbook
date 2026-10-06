<?php

declare(strict_types=1);

/*
 * A SESSION_SECRET for a fresh Docker volume (spec.md §9, Phase 36.1): run
 * by the Docker entrypoint after the migrations. With SESSION_SECRET empty
 * and SESSION_SECRET_FILE set (the image sets /data/session-secret), it
 * writes a random secret to that file, but only while the database has no
 * users. It never replaces the file, and never gives an install that has
 * users a secret (that would sign everyone out).
 *
 *   php bin/session-secret.php
 *
 * Exit code: 0 done or nothing to do, 1 failed.
 */

use Logbook\Kernel;
use Logbook\Support\Security\SessionSecretFile;

require dirname(__DIR__) . '/vendor/autoload.php';

$container = Kernel::createApp(Kernel::settings())->getContainer();
$file = $container->get(SessionSecretFile::class);
assert($file instanceof SessionSecretFile);

try {
    $outcome = $file->ensure();
} catch (Throwable $e) {
    fwrite(STDERR, 'logbook: the session secret could not be written: ' . $e->getMessage() . "\n");
    exit(1);
}

$message = match ($outcome) {
    SessionSecretFile::GENERATED => 'logbook: generated a SESSION_SECRET in SESSION_SECRET_FILE; keep it with your backups.',
    SessionSecretFile::EXISTING_INSTALL => 'logbook: SESSION_SECRET is not set. Saved passwords and tokens can only be'
        . ' env: references until you set one (see docs/configuration.md).',
    default => null,
};
if ($message !== null) {
    fwrite(STDOUT, $message . "\n");
}
exit(0);
