<?php

declare(strict_types=1);

/*
 * The upgrade path's first half (Phase 45.2, spec.md §7.13), run by
 * bin/upgrade-check.sh inside an older release's checkout, with this
 * version's tests/Support/{Golden,Household} and tests/Fixtures/golden
 * copied in: on that release, load the golden household into the empty
 * TEST_DB_* database, back it up to <backup.zip>, and restore that backup
 * there. The database and uploads are left for this version to upgrade.
 *
 *   APP_ENV=testing php tests/Fixtures/golden/upgrade-source.php <backup.zip>
 */

use Logbook\Kernel;
use Logbook\Service\Backup\BackupService;
use Logbook\Tests\Support\Golden\InputBuilder;
use Logbook\Tests\Support\Household\Household;
use Logbook\Tests\Support\MutableClock;
use Psr\Clock\ClockInterface;

require getcwd() . '/vendor/autoload.php';

$backup = $argv[1] ?? '';
if ($backup === '') {
    fwrite(STDERR, "Usage: php tests/Fixtures/golden/upgrade-source.php <backup.zip>\n");
    exit(2);
}

$container = Kernel::createContainer(Kernel::settings());
assert($container instanceof DI\Container);
$container->set(ClockInterface::class, new MutableClock(new DateTimeImmutable(InputBuilder::TODAY_UTC, new DateTimeZone('UTC'))));

Household::load($container, 'correct horse battery staple');
$backups = $container->get(BackupService::class);
assert($backups instanceof BackupService);
$manifest = $backups->create($backup);
$backups->restore($backup);

printf("Loaded, backed up and restored %d vehicles and %d files.\n", $manifest->rows('vehicles'), $manifest->files);
