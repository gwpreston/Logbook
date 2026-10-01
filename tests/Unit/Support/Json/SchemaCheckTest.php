<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Json;

use Logbook\Support\Json\SchemaCheck;
use PHPUnit\Framework\TestCase;

/**
 * The JSON Schema subset every structured model answer passes (spec.md §5
 * *AI adapters*).
 */
final class SchemaCheckTest extends TestCase
{
    private const array RECEIPT = [
        'type' => 'object',
        'properties' => [
            'date' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 10],
            'litres' => ['type' => ['number', 'null'], 'minimum' => 0],
            'fuel' => ['type' => 'string', 'enum' => ['petrol', 'diesel']],
            'lines' => [
                'type' => 'array',
                'items' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
            ],
            'count' => ['type' => 'integer', 'maximum' => 10],
        ],
        'required' => ['date', 'litres'],
        'additionalProperties' => false,
    ];

    public function testAFittingObjectHasNoErrors(): void
    {
        self::assertSame([], SchemaCheck::errors([
            'date' => '2026-10-01',
            'litres' => 41.235,
            'fuel' => 'diesel',
            'lines' => [['text' => 'Diesel'], ['text' => 'Wash']],
            'count' => 2,
        ], self::RECEIPT));
        self::assertTrue(SchemaCheck::fits(['date' => '2026-10-01', 'litres' => null], self::RECEIPT));
    }

    public function testEachProblemNamesItsPath(): void
    {
        self::assertSame([
            '$.litres: required',
            '$.date: shorter than 10',
            '$.fuel: not one of the allowed values',
            '$.lines[1].text: required',
            '$.count: above 10',
            '$.extra: not allowed',
        ], SchemaCheck::errors([
            'date' => '1/10/26',
            'fuel' => 'lpg',
            'lines' => [['text' => 'a'], []],
            'count' => 11,
            'extra' => true,
        ], self::RECEIPT));
    }

    public function testTypes(): void
    {
        self::assertSame(['$: expected object'], SchemaCheck::errors(['a', 'b'], ['type' => 'object']));
        self::assertSame(['$: expected integer'], SchemaCheck::errors(1.5, ['type' => 'integer']));
        self::assertSame([], SchemaCheck::errors(3.0, ['type' => 'integer']));
        self::assertSame(['$: expected number'], SchemaCheck::errors('3', ['type' => 'number']));
        self::assertSame([], SchemaCheck::errors(-2, ['type' => 'number', 'minimum' => -5]));
        self::assertSame(['$: below 0'], SchemaCheck::errors(-1, ['minimum' => 0]));
    }

    public function testUnknownKeywordsAreIgnored(): void
    {
        self::assertSame([], SchemaCheck::errors('x', ['type' => 'string', 'format' => 'date', 'description' => 'd']));
    }
}
