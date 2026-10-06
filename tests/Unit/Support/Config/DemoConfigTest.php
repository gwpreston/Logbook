<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Config;

use Logbook\Support\Config\DemoConfig;
use Logbook\Support\Config\Env;
use PHPUnit\Framework\TestCase;

final class DemoConfigTest extends TestCase
{
    public function testItIsOffByDefaultWithADayBetweenResets(): void
    {
        $config = DemoConfig::fromEnv(new Env([]));

        self::assertFalse($config->enabled);
        self::assertSame('', $config->password);
        self::assertSame(24, $config->resetHours);
        self::assertFalse($config->passwordUsable(), 'there is no default password');
    }

    public function testTheResetIntervalStaysBetweenOneHourAndAWeek(): void
    {
        self::assertSame(1, DemoConfig::fromEnv(new Env(['DEMO_RESET_HOURS' => '0']))->resetHours);
        self::assertSame(1, DemoConfig::fromEnv(new Env(['DEMO_RESET_HOURS' => '-5']))->resetHours);
        self::assertSame(6, DemoConfig::fromEnv(new Env(['DEMO_RESET_HOURS' => '6']))->resetHours);
        self::assertSame(168, DemoConfig::fromEnv(new Env(['DEMO_RESET_HOURS' => '500']))->resetHours);
    }

    public function testThePasswordNeedsEightToAThousandAndTwentyFourCharacters(): void
    {
        $with = static fn (string $password): bool => DemoConfig::fromEnv(new Env([
            'DEMO_MODE' => 'true',
            'DEMO_PASSWORD' => $password,
        ]))->passwordUsable();

        self::assertFalse($with('short'));
        self::assertFalse($with(str_repeat('a', 7)));
        self::assertTrue($with(str_repeat('a', 8)));
        self::assertTrue($with(str_repeat('é', 1024)));
        self::assertFalse($with(str_repeat('a', 1025)));
    }
}
