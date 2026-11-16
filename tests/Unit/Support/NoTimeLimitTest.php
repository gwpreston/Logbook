<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * K210: a time limit a test sets (as a job action does through
 * `LongRequest::allow()`) is gone before the next test starts, so on
 * Windows, where PHP counts it in wall-clock time, it can't kill a later
 * slow test. Needs the NoTimeLimit extension that phpunit.xml.dist
 * registers.
 */
final class NoTimeLimitTest extends TestCase
{
    public function testATestMaySetATimeLimit(): void
    {
        set_time_limit(60);

        self::assertSame('60', ini_get('max_execution_time'));
    }

    #[Depends('testATestMaySetATimeLimit')]
    public function testTheNextTestStartsWithNone(): void
    {
        self::assertSame('0', ini_get('max_execution_time'));
    }
}
