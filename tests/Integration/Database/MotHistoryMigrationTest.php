<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Logbook\Repository\SettingRepository;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;

/**
 * Rolling back Phase 41's MOT history migration also deletes the
 * `mot_history` settings (Phase 47.1, #348): its secrets table is dropped, so
 * a provider left on would come back on without credentials after upgrading
 * again. Other settings stay.
 */
final class MotHistoryMigrationTest extends AppTestCase
{
    /** The migration before it (Phase 40.1's issues). */
    private const string BEFORE = '20261107100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testRollingBackDeletesTheProviderSettingsSoItComesBackOff(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $settings = $this->service($app, SettingRepository::class);
        $settings->save(MotHistoryConfig::SETTING, ['provider' => 'dvsa']);
        $settings->save(MotHistoryConfig::STATUS, ['ok' => true]);
        $settings->save('mot_history_other', ['kept' => true]);
        $db = $this->connection($app);
        self::assertEquals(3, $db->fetchOne("SELECT COUNT(*) FROM settings WHERE name LIKE 'mot_history%'"));

        Migrator::run('rollback', ['--target' => self::BEFORE]);
        self::assertEquals(
            ['mot_history_other'],
            $db->fetchFirstColumn("SELECT name FROM settings WHERE name LIKE 'mot_history%'"),
            'only the two MOT history settings go',
        );

        Migrator::run('migrate');
        self::assertEquals(0, $db->fetchOne("SELECT COUNT(*) FROM settings WHERE name IN ('mot_history', 'mot_history.status')"));
        self::assertNull($this->service($app, MotHistoryConfig::class)->providerCode(), 'MOT history comes back off');
    }
}
