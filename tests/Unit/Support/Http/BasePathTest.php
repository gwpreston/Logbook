<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Http;

use InvalidArgumentException;
use Logbook\Support\Http\BasePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BasePathTest extends TestCase
{
    /**
     * @return iterable<array{string, string}>
     */
    public static function normalisation(): iterable
    {
        yield ['', ''];
        yield ['/', ''];
        yield ['logbook', '/logbook'];
        yield ['/logbook/', '/logbook'];
        yield ['  /apps/logbook  ', '/apps/logbook'];
    }

    #[DataProvider('normalisation')]
    public function testNormalise(string $input, string $expected): void
    {
        self::assertSame($expected, BasePath::normalise($input));
    }

    public function testRejectsUnsafeValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BasePath::normalise('/log book?x=1');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function prefixing(): iterable
    {
        yield 'no base path' => ['', '/vehicles/3', '/vehicles/3'];
        yield 'forwarded with prefix' => ['/logbook', '/logbook/vehicles/3', '/logbook/vehicles/3'];
        yield 'prefix stripped by proxy' => ['/logbook', '/vehicles/3', '/logbook/vehicles/3'];
        yield 'bare base path' => ['/logbook', '/logbook', '/logbook/'];
        yield 'root, prefix stripped' => ['/logbook', '/', '/logbook/'];
        yield 'similar name is not the prefix' => ['/logbook', '/logbooks', '/logbook/logbooks'];
    }

    #[DataProvider('prefixing')]
    public function testEnsurePrefixed(string $basePath, string $path, string $expected): void
    {
        self::assertSame($expected, BasePath::ensurePrefixed($basePath, $path));
    }
}
