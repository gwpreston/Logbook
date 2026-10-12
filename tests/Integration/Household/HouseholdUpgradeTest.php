<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Household;

use Logbook\Service\Backup\BackupService;
use Logbook\Support\Storage\FileStorage;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Golden\InputBuilder;
use Logbook\Tests\Support\Household\InstanceSnapshot;
use Logbook\Tests\Support\Household\LeakSweeping;

/**
 * The upgrade path's second half (Phase 45.2, #417): the golden household
 * entered on an older release, backed up and restored there, then upgraded
 * to this version (bin/upgrade-check.sh), must give every figure that the
 * same household entered on this version gives, with the same files.
 *
 * Skipped unless UPGRADED_BACKUP names the upgraded instance's backup.
 * Rows are compared by count, not value: ids of files, password hashes and
 * the like are made afresh on each entry.
 */
final class HouseholdUpgradeTest extends AppTestCase
{
    use LeakSweeping;

    public function testAnUpgradedHouseholdGivesWhatOneEnteredOnThisVersionGives(): void
    {
        $backup = getenv('UPGRADED_BACKUP');
        if (!is_string($backup) || $backup === '') {
            self::markTestSkipped('Set UPGRADED_BACKUP: bin/upgrade-check.sh does.');
        }
        $from = getenv('UPGRADED_FROM') ?: 'an older release';

        $app = $this->createApp(['BACKUP_PATH' => sys_get_temp_dir()]);
        $this->pinClock($app, InputBuilder::TODAY_UTC);
        $this->resetDatabase($app);
        $this->service($app, BackupService::class)->restore($backup);
        $upgraded = InstanceSnapshot::take($app->getContainer());

        $this->resetDatabase($app);
        $storage = $this->service($app, FileStorage::class);
        foreach ($storage->all() as $relative) {
            $storage->delete($relative);
        }
        $this->loadHousehold($app);
        $fresh = InstanceSnapshot::take($app->getContainer());

        $wrong = [];
        foreach ($fresh->figures as $id => $figure) {
            $then = $upgraded->figures[$id] ?? 'missing';
            if ($then !== $figure) {
                $wrong[] = "golden row {$id}: {$figure} entered here, {$then} from {$from}";
            }
        }
        foreach ($fresh->tables as $table => $rows) {
            $then = count($upgraded->tables[$table] ?? []);
            if (count($rows) !== $then) {
                $wrong[] = sprintf('%s: %d rows entered here, %d from %s', $table, count($rows), $then, $from);
            }
        }
        $hashes = static fn (InstanceSnapshot $s): array => self::sorted(array_values($s->files));
        if ($hashes($fresh) !== $hashes($upgraded)) {
            $wrong[] = "the files differ from {$from}'s";
        }

        self::assertSame([], $wrong, implode("\n", $wrong));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
