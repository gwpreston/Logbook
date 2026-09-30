<?php

declare(strict_types=1);

/*
 * Export one user's vehicles (spec.md §7.13), for moving them to an install
 * of their own: a backup-format ZIP with their account (an admin there),
 * their own settings and API keys, and every vehicle they own with all its
 * entries, schedules, reminders and files. Shares and other users are left
 * out. Restore it on a fresh install of the same version:
 *
 *   php bin/export-user.php <username> [file]    # default: a new file in BACKUP_PATH
 *   php bin/backup.php restore <file> --yes      # on the new install
 *
 * Also the way to roll back below 2.0.0 with several users: export each of
 * the others, delete them in Settings → Users, then roll back.
 *
 * Docker: docker compose exec -u www-data app php bin/export-user.php sam
 *
 * Exit code: 0 ok, 1 failed, 2 usage.
 */

use Logbook\Domain\User\Username;
use Logbook\Kernel;
use Logbook\Repository\UserRepository;
use Logbook\Service\Backup\BackupService;

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = "Usage: php bin/export-user.php <username> [file]\n";
$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));
$username = $args[1] ?? '';
$file = $args[2] ?? null;
if ($username === '' || str_starts_with($username, '-')) {
    fwrite(STDERR, $usage);
    exit(2);
}

$settings = Kernel::settings();
$container = Kernel::createContainer($settings);
$backups = $container->get(BackupService::class);
assert($backups instanceof BackupService);
$users = $container->get(UserRepository::class);
assert($users instanceof UserRepository);

if (!BackupService::isAvailable()) {
    fwrite(STDERR, "Exports need PHP's zip extension (php-zip).\n");
    exit(1);
}

$user = $users->findByUsername(Username::normalise($username));
if ($user === null) {
    fwrite(STDERR, sprintf("There is no user \"%s\".\n", $username));
    exit(1);
}

try {
    if ($file === null) {
        $dir = rtrim($settings->backupPath, '/\\');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            fwrite(STDERR, "Cannot create {$dir} (BACKUP_PATH).\n");
            exit(1);
        }
        $file = $dir . '/' . $backups->filename('logbook-export-' . $user->username);
    }
    $manifest = $backups->createForUser($file, $user);
    fwrite(STDOUT, sprintf(
        "Exported %s to %s (%d vehicle(s), %d file(s)).\n",
        $user->username,
        $file,
        $manifest->tables['vehicles'] ?? 0,
        $manifest->files,
    ));
} catch (Throwable $e) {
    fwrite(STDERR, 'Export failed: ' . $e->getMessage() . "\n");
    exit(1);
}
