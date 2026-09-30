<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Api;

use DateTimeImmutable;
use Logbook\Support\Api\FailedKeyThrottle;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

final class FailedKeyThrottleTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/logbook-throttle-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/var/cache/api-throttle/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (['/var/cache/api-throttle', '/var/cache', '/var', ''] as $dir) {
            @rmdir($this->root . $dir);
        }
    }

    public function testTheTwentiethFailureInTenMinutesBlocksForTenMinutes(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-09-29T07:00:00Z'));
        $throttle = new FailedKeyThrottle(AppSettings::fromEnv(new Env([]), $this->root), $clock);

        for ($i = 1; $i < FailedKeyThrottle::MAX_FAILURES; ++$i) {
            self::assertFalse($throttle->recordFailure('203.0.113.9'));
        }
        self::assertNull($throttle->blockedFor('203.0.113.9'));
        self::assertTrue($throttle->recordFailure('203.0.113.9'), 'the twentieth starts the block');
        self::assertSame(600, $throttle->blockedFor('203.0.113.9'));
        self::assertNull($throttle->blockedFor('203.0.113.10'));

        $clock->set(new DateTimeImmutable('2026-09-29T07:10:00Z'));
        self::assertNull($throttle->blockedFor('203.0.113.9'));
        self::assertFalse($throttle->recordFailure('203.0.113.9'), 'counting starts again');
    }

    public function testAnUnreadableCounterCountsAsNone(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-09-29T07:00:00Z'));
        $throttle = new FailedKeyThrottle(AppSettings::fromEnv(new Env([]), $this->root), $clock);
        $throttle->recordFailure('198.51.100.1');
        foreach (glob($this->root . '/var/cache/api-throttle/*.json') ?: [] as $file) {
            file_put_contents($file, '{not json');
        }

        self::assertNull($throttle->blockedFor('198.51.100.1'));
        self::assertFalse($throttle->recordFailure('198.51.100.1'));
    }
}
