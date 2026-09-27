<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Validation;

use Logbook\Domain\Vehicle\FuelType;
use Logbook\Support\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredAndOptionalStrings(): void
    {
        $v = new Validator(['name' => '  Golf  ', 'blank' => '   ', 'long' => str_repeat('x', 11)], 'en');

        self::assertSame('Golf', $v->string('name', true));
        self::assertNull($v->string('blank'));
        self::assertNull($v->string('missing'));
        self::assertNull($v->string('absent', true));
        self::assertNull($v->string('long', false, 10));

        $errors = $v->errors()->all();
        self::assertSame('validation.required', $errors['absent']['key']);
        self::assertSame(['key' => 'validation.too_long', 'params' => ['max' => 10]], $errors['long']);
        self::assertFalse($v->errors()->has('blank'));
    }

    public function testArraysAndInvalidUtf8AreRejected(): void
    {
        $v = new Validator(['arr' => ['x'], 'bad' => "\xC3\x28"], 'en');

        self::assertNull($v->string('arr', true));
        self::assertNull($v->string('bad'));
        self::assertSame('validation.required', $v->errors()->all()['arr']['key']);
        self::assertSame('validation.invalid', $v->errors()->all()['bad']['key']);
    }

    public function testZeroIsALegitimateDecimal(): void
    {
        $v = new Validator(['cost' => '0', 'price' => '0.000'], 'en');

        self::assertSame('0.000', $v->decimal('cost', true));
        self::assertSame('0.000', $v->decimal('price', true));
        self::assertTrue($v->errors()->isEmpty());
    }

    public function testDecimalsKeepThreePlacesAndRoundTheRest(): void
    {
        $v = new Validator(['price' => '1.459', 'volume' => '45.12345', 'locale' => '1.234,5'], 'de');

        self::assertSame('1.459', $v->decimal('price'));
        self::assertSame('45.123', $v->decimal('volume'));
        self::assertSame('1234.500', $v->decimal('locale'));
        self::assertTrue($v->errors()->isEmpty());
    }

    public function testDecimalBounds(): void
    {
        $v = new Validator(['neg' => '-1', 'big' => '1234567890', 'text' => 'ten', 'high' => '101'], 'en');

        self::assertNull($v->decimal('neg'));
        self::assertNull($v->decimal('big', false, 3, '0', null, 9));
        self::assertNull($v->decimal('text'));
        self::assertNull($v->decimal('high', false, 3, '0', '100'));

        $errors = $v->errors()->all();
        self::assertSame(['key' => 'validation.min', 'params' => ['min' => '0']], $errors['neg']);
        self::assertSame('validation.too_large', $errors['big']['key']);
        self::assertSame('validation.number', $errors['text']['key']);
        self::assertSame(['key' => 'validation.max', 'params' => ['max' => '100']], $errors['high']);
    }

    public function testNegativeAllowedWhenAsked(): void
    {
        $v = new Validator(['delta' => '-2.5'], 'en');

        self::assertSame('-2.500', $v->decimal('delta', false, 3, null));
    }

    public function testIntegers(): void
    {
        $v = new Validator(['year' => '2019', 'old' => '1800', 'frac' => '20.5'], 'en');

        self::assertSame(2019, $v->integer('year', true, 1885, 2027));
        self::assertNull($v->integer('old', false, 1885));
        self::assertNull($v->integer('frac'));
        self::assertSame('validation.integer', $v->errors()->all()['frac']['key']);
    }

    public function testDatesEnumsAndChoices(): void
    {
        $v = new Validator(['d' => '2026-02-30', 'ok' => '2026-09-27', 'fuel' => 'ev', 'bad' => 'coal', 'c' => 'GBP'], 'en');

        self::assertNull($v->date('d'));
        self::assertSame('2026-09-27', $v->date('ok')?->format('Y-m-d'));
        self::assertSame(FuelType::Electric, $v->enum('fuel', FuelType::class, true));
        self::assertNull($v->enum('bad', FuelType::class));
        self::assertSame('GBP', $v->choice('c', ['GBP', 'EUR']));
        self::assertSame('validation.date', $v->errors()->all()['d']['key']);
        self::assertSame('validation.choice', $v->errors()->all()['bad']['key']);
    }

    public function testPasswordsAreNotTrimmed(): void
    {
        $v = new Validator(['p' => '  spaced out  ', 'short' => 'abc'], 'en');

        self::assertSame('  spaced out  ', $v->password('p'));
        self::assertNull($v->password('short'));
        self::assertSame(['min' => '8', 'max' => '1024'], $v->errors()->all()['short']['params']);
    }

    public function testCheckbox(): void
    {
        $v = new Validator(['on' => '1', 'off' => '0'], 'en');

        self::assertTrue($v->checkbox('on'));
        self::assertFalse($v->checkbox('off'));
        self::assertFalse($v->checkbox('missing'));
    }

    public function testOnlyTheFirstErrorPerFieldIsKept(): void
    {
        $v = new Validator([], 'en');
        $v->addError('x', 'first');
        $v->addError('x', 'second');

        self::assertSame('first', $v->errors()->all()['x']['key']);
    }
}
