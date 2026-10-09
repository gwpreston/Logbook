<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Database;

use Logbook\Support\Cache\RequestReads;
use Logbook\Support\Database\ForgetWrittenReadsMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ForgetWrittenReadsMiddlewareTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function statements(): iterable
    {
        yield 'select' => ['SELECT * FROM fuel_entries WHERE id = ?', null];
        yield 'select in parentheses' => ['(SELECT 1)', null];
        yield 'with' => ['WITH x AS (SELECT 1) SELECT * FROM x', null];
        yield 'pragma' => ['PRAGMA foreign_keys = ON', null];
        yield 'set' => ["SET TIME ZONE 'UTC'", null];
        yield 'transaction' => ['COMMIT', null];
        yield 'insert' => ['INSERT INTO fuel_entries (a) VALUES (?)', 'fuel_entries'];
        yield 'quoted insert' => ['INSERT INTO "settings" (a) VALUES (?)', 'settings'];
        yield 'backtick insert' => ['insert into `settings` (a) values (?)', 'settings'];
        yield 'insert ignore' => ['INSERT IGNORE INTO reminders (a) VALUES (?)', 'reminders'];
        yield 'replace' => ['REPLACE INTO settings (a) VALUES (?)', 'settings'];
        yield 'update' => ["  UPDATE\nodometer_readings SET a = ?", 'odometer_readings'];
        yield 'delete' => ['DELETE FROM tyre_changes WHERE id = ?', 'tyre_changes'];
        yield 'a vehicle takes its rows with it' => ['DELETE FROM vehicles WHERE id = ?', '*'];
        yield 'so does a user' => ['UPDATE users SET a = ?', '*'];
        yield 'rolling back to a savepoint undoes writes to any table' => ['ROLLBACK TO SAVEPOINT s1', '*'];
        yield 'ddl' => ['ALTER TABLE x ADD COLUMN y INT', '*'];
        yield 'truncate' => ['TRUNCATE TABLE settings', '*'];
    }

    #[DataProvider('statements')]
    public function testWhichTableAStatementWrites(string $sql, ?string $expected): void
    {
        self::assertSame($expected, ForgetWrittenReadsMiddleware::tableWritten($sql));
    }

    public function testAWriteMakesTheRequestForgetWhatCameFromThatTable(): void
    {
        $reads = new RequestReads();
        $reads->begin();
        $reads->remember('settings', 'x', static fn (): string => 'old');
        $reads->remember('fuel_entries', 1, static fn (): string => 'fuel');

        ForgetWrittenReadsMiddleware::forgetFor($reads, 'SELECT * FROM settings');
        self::assertSame('old', $reads->remember('settings', 'x', static fn (): string => 'new'), 'a read forgets nothing');

        ForgetWrittenReadsMiddleware::forgetFor($reads, 'UPDATE settings SET value = ?');
        self::assertSame('new', $reads->remember('settings', 'x', static fn (): string => 'new'));
        self::assertSame('fuel', $reads->remember('fuel_entries', 1, static fn (): string => 'other'), 'other tables stay');

        ForgetWrittenReadsMiddleware::forgetFor($reads, 'DELETE FROM vehicles WHERE id = ?');
        self::assertSame('again', $reads->remember('fuel_entries', 1, static fn (): string => 'again'));
    }

    public function testNothingIsForgottenOutsideARequest(): void
    {
        $reads = new RequestReads();

        ForgetWrittenReadsMiddleware::forgetFor($reads, 'DELETE FROM vehicles');

        self::assertFalse($reads->isActive());
    }
}
