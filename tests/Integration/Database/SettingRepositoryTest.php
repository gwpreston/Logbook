<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\SettingRepository;
use Logbook\Support\Cache\RequestReads;
use Logbook\Support\Database\Row;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\MutableClock;
use Logbook\Tests\Support\QueryCounter;

/**
 * Round-trips through DBAL on whichever engine TEST_DB_* selects; CI runs this
 * against PostgreSQL, MySQL and MariaDB.
 */
final class SettingRepositoryTest extends AppTestCase
{
    private Connection $connection;
    private MutableClock $clock;
    private SettingRepository $repository;
    private RequestReads $reads;
    private QueryCounter $counter;

    protected function setUp(): void
    {
        $app = $this->createApp();
        $this->counter = QueryCounter::install($app);
        $this->connection = $this->connection($app);
        $this->connection->executeStatement('DELETE FROM settings');
        $this->clock = new MutableClock(new DateTimeImmutable('2026-07-01 09:30:00', new DateTimeZone('Europe/London')));
        // The app's own RequestReads, which its connection tells about every write.
        $this->reads = $this->service($app, RequestReads::class);
        $this->repository = new SettingRepository($this->connection, $this->clock, $this->reads);
    }

    protected function tearDown(): void
    {
        $this->reads->end();
    }

    /**
     * Phase 41.7: during a page request all of an owner's settings are one query.
     */
    public function testAPageRequestReadsAnOwnersSettingsOnce(): void
    {
        $this->repository->save('a', 1);
        $this->repository->save('b', 2);
        $this->repository->save('c', 3, SettingScope::User, 7);

        $this->reads->begin();
        $queries = $this->counter->during(function (): void {
            self::assertSame(1, $this->value('a'));
            self::assertSame(2, $this->value('b'));
            self::assertNull($this->repository->find('missing'));
            self::assertSame(3, $this->value('c', SettingScope::User, 7));
            $this->value('c', SettingScope::User, 7);
            $this->value('a');
        });

        self::assertSame(2, $queries, 'one for the global settings, one for the user\'s');
    }

    public function testOutsideAPageRequestEveryReadGoesToTheDatabase(): void
    {
        $this->repository->save('a', 1);

        $queries = $this->counter->during(function (): void {
            $this->repository->find('a');
            $this->repository->find('a');
        });

        self::assertSame(2, $queries);
    }

    public function testAWriteDuringARequestIsSeenByTheNextRead(): void
    {
        $this->repository->save('a', 1);
        $this->reads->begin();
        self::assertSame(1, $this->value('a'));

        $this->repository->save('a', 2);
        self::assertSame(2, $this->value('a'), 'saved through the repository');

        $this->connection->executeStatement("UPDATE settings SET value = '3' WHERE name = 'a'");
        self::assertSame(3, $this->value('a'), 'written by anything else on the connection');

        $this->repository->delete('a');
        self::assertNull($this->value('a'));
    }

    public function testARolledBackWriteIsNotRemembered(): void
    {
        $this->reads->begin();
        $this->connection->beginTransaction();
        $this->repository->save('x', 1);
        self::assertSame(1, $this->value('x'));

        $this->connection->rollBack();

        self::assertNull($this->value('x'));
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

    private function value(string $name, SettingScope $scope = SettingScope::Global, int $ownerId = 0): mixed
    {
        return $this->repository->find($name, $scope, $ownerId)?->value;
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
