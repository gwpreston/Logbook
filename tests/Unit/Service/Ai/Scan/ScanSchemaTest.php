<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Scan;

use Logbook\Service\Ai\Scan\Extraction;
use Logbook\Service\Ai\Scan\ScanSchema;
use Logbook\Support\Json\SchemaCheck;
use PHPUnit\Framework\TestCase;

/**
 * The scan's answer schema (spec.md §7.27; Phase 53.8, #534): each field
 * is null or carries both its value and its evidence, so a server that
 * holds the model to the schema can't answer `{}` for every field.
 */
final class ScanSchemaTest extends TestCase
{
    public function testEveryFieldRequiresItsValueAndEvidence(): void
    {
        $properties = ScanSchema::schema()['properties'] ?? null;
        self::assertIsArray($properties);
        $object = $properties['fields'] ?? null;
        self::assertIsArray($object);
        $fields = $object['properties'] ?? null;
        self::assertIsArray($fields);
        self::assertNotEmpty($fields);

        self::assertSame(['kind', 'fields', 'lines'], ScanSchema::schema()['required'] ?? null);
        self::assertSame(array_keys($fields), $object['required'] ?? null, 'every field is named, null when absent');
        $lines = $properties['lines'] ?? null;
        self::assertIsArray($lines);
        self::assertIsArray($lines['properties'] ?? null);
        self::assertSame(array_keys($lines['properties']), $lines['required'] ?? null);
        foreach ($lines['properties'] as $name => $list) {
            self::assertIsArray($list);
            self::assertContains('null', (array) ($list['type'] ?? []), (string) $name . ' may be null');
        }

        foreach ($fields as $name => $field) {
            self::assertIsArray($field);
            self::assertSame(['value', 'evidence'], $field['required'] ?? null, (string) $name);
            self::assertSame(['object', 'null'], $field['type'] ?? null, (string) $name . ' may still be null');
        }
    }

    public function testAnAnswerLeavingKeysOutStillFitsWhatItIsCheckedAgainst(): void
    {
        // Providers that don't enforce the schema may leave a key out; the answer isn't refused for it.
        $answer = ['kind' => 'fuel_receipt', 'fields' => ['total' => ['value' => '60.28']]];

        self::assertSame([], SchemaCheck::errors($answer, ScanSchema::schema(false)));
        self::assertNotSame([], SchemaCheck::errors($answer, ScanSchema::schema()), 'the model is held to more');
        self::assertSame('60.28', Extraction::fromObject($answer)->value('total'));
    }

    public function testAnAnswerNamingEveryFieldAsNullReadsAsEmpty(): void
    {
        $fields = array_fill_keys(array_keys(ScanSchema::FIELDS), null);
        $fields['total'] = ['value' => '60.28', 'evidence' => 'TOTAL 60.28'];
        $read = Extraction::fromObject(['kind' => 'fuel_receipt', 'fields' => $fields, 'lines' => ['work' => null]]);

        self::assertSame('60.28', $read->value('total'));
        self::assertNull($read->value('date'));
    }
}
