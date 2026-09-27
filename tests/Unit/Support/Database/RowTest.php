<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Database;

use Logbook\Support\Database\Row;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class RowTest extends TestCase
{
    public function testNormalisesDriverSpecificScalars(): void
    {
        // pdo_mysql may return integers as strings; pdo_pgsql returns ints.
        self::assertSame(7, Row::int(['id' => '7'], 'id'));
        self::assertSame(7, Row::int(['id' => 7], 'id'));
        self::assertSame(-3, Row::int(['n' => '-3'], 'n'));
        self::assertSame('42', Row::string(['v' => 42], 'v'));
    }

    public function testColumnLookupIsCaseInsensitiveAsAFallback(): void
    {
        self::assertSame(1, Row::int(['N' => '1'], 'n'));
    }

    public function testRejectsNonIntegers(): void
    {
        $this->expectException(UnexpectedValueException::class);
        Row::int(['id' => '7.5'], 'id');
    }

    public function testRejectsMissingColumns(): void
    {
        $this->expectException(UnexpectedValueException::class);
        Row::string(['a' => 'x'], 'b');
    }

    public function testRejectsNullForRequiredStrings(): void
    {
        $this->expectException(UnexpectedValueException::class);
        Row::string(['a' => null], 'a');
    }
}
