<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\SettingRepository;
use Logbook\Support\Database\Row;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\MutableClock;

/**
 * Round-trips through DBAL on whichever engine TEST_DB_* selects; CI runs this
 * against PostgreSQL, MySQL and MariaDB.
 */
final class SettingRepositoryTest extends AppTestCase
{
    private Connection $connection;
    private MutableClock $clock;
    private SettingRepository $repository;

    protected function setUp(): void
    {
        $this->connection = $this->connection($this->createApp());
        $this->connection->executeStatement('DELETE FROM settings');
        $this->clock = new MutableClock(new DateTimeImmutable('2026-07-01 09:30:00', new DateTimeZone('Europe/London')));
        $this->repository = new SettingRepository($this->connection, $this->clock);
    }

    public function testMissingSettingIsNull(): void
    {
        self::assertNull($this->repository->find('nope'));
    }

    public function testRoundTripsStructuredJsonAndUnicode(): void
    {
        $value = [
            'enabled' => true,
            'modules' => ['fuel', 'maintenance'],
            'price' => '1.859',
            'label' => 'Grüße — 燃料 ⛽',
            'nested' => ['zero' => 0, 'null' => null],
        ];

        $this->repository->save('dashboard.layout', $value);
        $setting = $this->repository->find('dashboard.layout');

        self::assertNotNull($setting);
        // MySQL's JSON type does not preserve object key order (PostgreSQL's
        // json does), so compare order-insensitively but type-strictly. Lists
        // keep their order everywhere — use lists when order matters.
        self::assertSame(self::sortKeys($value), self::sortKeys($setting->value));
        self::assertSame(['fuel', 'maintenance'], self::sortKeys($setting->value)['modules'] ?? null);
        self::assertSame(SettingScope::Global, $setting->scope);
        self::assertSame(0, $setting->ownerId);
    }

    public function testTimestampsAreStoredAndReturnedInUtc(): void
    {
        $setting = $this->repository->save('tz', 'x');

        // 09:30 BST == 08:30 UTC
        self::assertSame('2026-07-01T08:30:00+00:00', $setting->createdAt->format(DATE_ATOM));
        self::assertSame(
            '2026-07-01 08:30:00',
            $this->connection->fetchOne('SELECT created_at FROM settings WHERE name = ?', ['tz']),
        );
    }

    public function testSavingAgainUpdatesInPlace(): void
    {
        $this->repository->save('units', 'metric');
        $this->clock->set(new DateTimeImmutable('2026-07-02 12:00:00', new DateTimeZone('UTC')));
        $updated = $this->repository->save('units', 'imperial');

        self::assertSame('imperial', $updated->value);
        self::assertSame('2026-07-01 08:30:00', $updated->createdAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-02 12:00:00', $updated->updatedAt->format('Y-m-d H:i:s'));
        self::assertSame(1, $this->countSettings());
    }

    public function testSavingTheSameValueTwiceInTheSameSecondIsSafe(): void
    {
        $this->repository->save('same', 'value');
        $this->repository->save('same', 'value');

        self::assertSame(1, $this->countSettings());
    }

    public function testScopesAreIndependent(): void
    {
        $this->repository->save('locale', 'en');
        $this->repository->save('locale', 'de', SettingScope::User, 7);

        self::assertSame('en', $this->repository->find('locale')?->value);
        self::assertSame('de', $this->repository->find('locale', SettingScope::User, 7)?->value);
        self::assertNull($this->repository->find('locale', SettingScope::User, 8));
    }

    public function testDelete(): void
    {
        $this->repository->save('gone', 1);
        $this->repository->delete('gone');

        self::assertNull($this->repository->find('gone'));
    }

    /**
     * Phase 37 (#269): only the caller whose read is still current removes
     * it; the value isn't compared, as JSON columns can't be everywhere.
     */
    public function testDeleteIfUnchangedRemovesItOnlyForTheFirstCaller(): void
    {
        $this->repository->save('held', ['backup' => 1], SettingScope::User, 7);
        $first = $this->repository->find('held', SettingScope::User, 7);
        $second = $this->repository->find('held', SettingScope::User, 7);
        self::assertNotNull($first);
        self::assertNotNull($second);

        self::assertTrue($this->repository->deleteIfUnchanged($first));
        self::assertFalse($this->repository->deleteIfUnchanged($second), 'already gone');
        self::assertNull($this->repository->find('held', SettingScope::User, 7));

        $read = $this->repository->save('held', ['backup' => 1], SettingScope::User, 7);
        $this->clock->set($this->clock->now()->modify('+1 minute'));
        $this->repository->save('held', ['backup' => 2], SettingScope::User, 7);
        self::assertFalse($this->repository->deleteIfUnchanged($read), 'saved again since it was read');
        self::assertSame(['backup' => 2], $this->repository->find('held', SettingScope::User, 7)?->value);
    }

    private function countSettings(): int
    {
        $row = $this->connection->fetchAssociative('SELECT COUNT(*) AS n FROM settings');
        self::assertIsArray($row);

        return Row::int($row, 'n');
    }

    /**
     * @return array<mixed>
     */
    private static function sortKeys(mixed $value): array
    {
        self::assertIsArray($value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(static fn (mixed $v): mixed => is_array($v) ? self::sortKeys($v) : $v, $value);
    }
}
