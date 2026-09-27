<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Config;

use InvalidArgumentException;
use Logbook\Support\Config\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function testEmptyStringCountsAsUnsetSoDefaultApplies(): void
    {
        $env = new Env(['A' => '', 'B' => '  value  ']);

        self::assertFalse($env->has('A'));
        self::assertSame('fallback', $env->string('A', 'fallback'));
        self::assertSame('value', $env->string('B'));
        self::assertNull($env->nullableString('MISSING'));
    }

    public function testParsesBooleansAndIntegers(): void
    {
        $env = new Env(['T' => 'true', 'F' => 'off', 'ONE' => '1', 'N' => '42']);

        self::assertTrue($env->bool('T', false));
        self::assertFalse($env->bool('F', true));
        self::assertTrue($env->bool('ONE', false));
        self::assertTrue($env->bool('MISSING', true));
        self::assertSame(42, $env->int('N', 0));
        self::assertSame(7, $env->int('MISSING', 7));
    }

    public function testRejectsMalformedInteger(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Env(['PORT' => 'abc']))->int('PORT', 5432);
    }

    public function testRejectsMalformedBoolean(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Env(['FLAG' => 'maybe']))->bool('FLAG', false);
    }

    public function testOverridesWin(): void
    {
        $env = (new Env(['A' => 'one']))->with(['A' => 'two']);

        self::assertSame('two', $env->string('A'));
    }

    public function testRealEnvironmentWinsOverDotenvFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($file);
        file_put_contents($file, "LOGBOOK_TEST_ONLY_IN_FILE=file\nLOGBOOK_TEST_IN_BOTH=file\n");
        putenv('LOGBOOK_TEST_IN_BOTH=process');

        try {
            $env = Env::fromSystem($file);
            self::assertSame('file', $env->string('LOGBOOK_TEST_ONLY_IN_FILE'));
            self::assertSame('process', $env->string('LOGBOOK_TEST_IN_BOTH'));
        } finally {
            putenv('LOGBOOK_TEST_IN_BOTH');
            unlink($file);
        }
    }
}
