<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Api;

use DateTimeImmutable;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonInputTest extends TestCase
{
    public function testNumbersBecomeTheirExactDigits(): void
    {
        $body = JsonInput::decode(
            '{"a": 0.1, "b": -40123.4565, "c": 60.0000000000000001, "d": 7, "e": "8.50", '
            . '"f": "x 1.5 y", "g": true, "h": null, "i": 1e5, "j": "say \"2.5\""}',
        );

        self::assertSame(
            [
                'a' => '0.1',
                'b' => '-40123.4565',
                'c' => '60.0000000000000001',
                'd' => '7',
                'e' => '8.50',
                'f' => 'x 1.5 y',
                'g' => true,
                'h' => null,
                'i' => '1e5',
                'j' => 'say "2.5"',
            ],
            $body,
        );
        self::assertSame([], JsonInput::decode(''));
        self::assertSame([], JsonInput::decode('{}'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAnObject(): iterable
    {
        yield 'a list' => ['[1, 2]'];
        yield 'a string' => ['"text"'];
        yield 'a number' => ['12'];
        yield 'broken' => ['{"a": }'];
        yield 'trailing text' => ['{"a": 1} x'];
    }

    #[DataProvider('notAnObject')]
    public function testAnythingButAnObjectIsRefused(string $body): void
    {
        try {
            JsonInput::decode($body);
            self::fail('accepted ' . $body);
        } catch (ApiProblem $problem) {
            self::assertSame(400, $problem->status);
            self::assertSame('invalid_body', $problem->problemCode);
        }
    }

    public function testAFillUpBecomesTheFormsInputInTheRequestsUnits(): void
    {
        $owner = new DisplayPreferences(
            'de',
            'Europe/Berlin',
            DistanceUnit::Kilometre,
            VolumeUnit::Litre,
            ConsumptionUnit::LitresPer100Km,
            'EUR',
        );

        $mapped = JsonInput::fuel(
            [
                'filled_at' => '2026-09-29T09:42:59+02:00',
                'odometer' => '30280',
                'distance_unit' => 'mi',
                'volume' => '8.5',
                'volume_unit' => 'gal_uk',
                'total_cost' => '61.20',
                'is_partial' => true,
                'grade' => 'e5_97',
            ],
            $owner,
            'petrol:e10_95',
            new DateTimeImmutable('2026-09-30T00:00:00Z'),
        );

        self::assertIsArray($mapped);
        self::assertSame('2026-09-29T07:42', $mapped['input']['filled_at'], 'UTC, to the minute');
        self::assertSame(['30280', '8.5', '', '61.20', '1', ''], [
            $mapped['input']['odometer'], $mapped['input']['volume'], $mapped['input']['price'],
            $mapped['input']['total'], $mapped['input']['partial'], $mapped['input']['missed_previous'],
        ]);
        self::assertSame(['petrol', 'e5_97'], [$mapped['input']['fuel'], $mapped['input']['grade']], 'a grade names its family');
        $preferences = $mapped['preferences'];
        self::assertSame(['en', 'UTC', DistanceUnit::Mile, VolumeUnit::UkGallon, 'EUR'], [
            $preferences->locale,
            $preferences->timezone,
            $preferences->distanceUnit,
            $preferences->volumeUnit,
            $preferences->currency,
        ]);
    }

    public function testDefaultsAreTheOwnersUnitsAndTheFormsFuel(): void
    {
        $owner = new DisplayPreferences(
            'en_US',
            'America/New_York',
            DistanceUnit::Mile,
            VolumeUnit::UsGallon,
            ConsumptionUnit::MpgUs,
            'USD',
        );

        $mapped = JsonInput::fuel(['odometer' => '100'], $owner, 'diesel', new DateTimeImmutable('2026-09-30T12:34:56Z'));

        self::assertIsArray($mapped);
        self::assertSame('2026-09-30T12:34', $mapped['input']['filled_at']);
        self::assertSame('diesel', $mapped['input']['fuel']);
        self::assertSame(
            [DistanceUnit::Mile, VolumeUnit::UsGallon],
            [$mapped['preferences']->distanceUnit, $mapped['preferences']->volumeUnit],
        );
    }

    public function testFormErrorsTakeTheApisFieldNames(): void
    {
        $form = new ValidationErrors();
        $form->add('price', 'validation.number');
        $form->add('total', 'validation.min', ['min' => '0']);
        $form->add('partial', 'validation.invalid');
        $form->add('fuel', 'validation.choice');

        $renamed = JsonInput::renamed($form, JsonInput::FUEL_FIELDS);

        self::assertSame(['price_per_unit', 'total_cost', 'is_partial', 'fuel'], array_keys($renamed->all()));
        self::assertSame(['min' => '0'], $renamed->all()['total_cost']['params']);
        self::assertSame(['odometer'], array_keys(JsonInput::renamed(self::errors('reading'), JsonInput::READING_FIELDS)->all()));
    }

    private static function errors(string $field): ValidationErrors
    {
        $errors = new ValidationErrors();
        $errors->add($field, 'validation.required');

        return $errors;
    }
}
