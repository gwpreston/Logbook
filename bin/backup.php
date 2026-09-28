<?php

declare(strict_types=1);

/*
 * Backup and restore from the command line (spec.md §7.13): the same ZIP
 * archives as Settings → Backup, without the upload limit.
 *
 *   php bin/backup.php create [file]           # default: a new file in BACKUP_PATH
 *   php bin/backup.php restore <file> --yes    # replaces ALL data (a safety backup is made first)
 *   php bin/backup.php check <file>            # validate only
 *
 * Nightly backups from cron (bare PHP), as the web server user:
 *
 *   30 3 * * *  www-data  cd /var/www/logbook && php bin/backup.php create
 *
 * Docker: docker compose exec -u www-data app php bin/backup.php create
 *
 * Exit code: 0 ok, 1 failed, 2 usage.
 */

use Logbook\Kernel;
use Logbook\Service\Backup\BackupService;
use Logbook\Service\Backup\InvalidBackup;
use Symfony\Contracts\Translation\TranslatorInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = "Usage: php bin/backup.php create [file] | restore <file> --yes | check <file>\n";
$command = $argv[1] ?? '';
$file = $argv[2] ?? null;

$settings = Kernel::settings();
$container = Kernel::createContainer($settings);
$backups = $container->get(BackupService::class);
assert($backups instanceof BackupService);
$translator = $container->get(TranslatorInterface::class);
assert($translator instanceof TranslatorInterface);

if (!BackupService::isAvailable()) {
    fwrite(STDERR, "Backups need PHP's zip extension (php-zip).\n");
    exit(1);
}

try {
    switch ($command) {
        case 'create':
            if ($file === null) {
                $dir = rtrim($settings->backupPath, '/\\');
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    fwrite(STDERR, "Cannot create {$dir} (BACKUP_PATH).\n");
                    exit(1);
                }
                $file = $dir . '/' . $backups->filename();
            }
            $manifest = $backups->create($file);
            fwrite(STDOUT, sprintf(
                "Backup written to %s (%d vehicle(s), %d file(s)).\n",
                $file,
                $manifest->rows('vehicles'),
                $manifest->files,
            ));
            exit(0);

        case 'check':
            if ($file === null) {
                fwrite(STDERR, $usage);
                exit(2);
            }
            $manifest = $backups->inspect($file);
            fwrite(STDOUT, sprintf(
                "OK: Logbook %s backup from %s (%d vehicle(s), %d file(s)).\n",
                $manifest->appVersion,
                $manifest->createdAt->format('Y-m-d H:i') . ' UTC',
                $manifest->rows('vehicles'),
                $manifest->files,
            ));
            exit(0);

        case 'restore':
            if ($file === null || !in_array('--yes', $argv, true)) {
                fwrite(STDERR, "Restoring replaces ALL data with the backup's. Add --yes to confirm.\n" . $usage);
                exit(2);
            }
            $safety = $backups->restore($file);
            fwrite(STDOUT, "Restored {$file}. The previous data was saved to {$safety}.\n");
            exit(0);

        default:
            fwrite(STDERR, $usage);
            exit(2);
    }
} catch (InvalidBackup $e) {
    fwrite(STDERR, $translator->trans($e->key, $e->params) . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
