<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Scan;

use Logbook\Service\Ai\Provider\StructuredOutput;
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

    public function testTheFlatFormHasNoUnionsOptionalKeysOrLengthLimits(): void
    {
        // Anthropic's output_config.format refuses maxLength and allows 16 unions and 24 optional keys (K132).
        $walk = static function (array $schema, string $path) use (&$walk): void {
            foreach (['maxLength', 'minLength', 'anyOf', 'oneOf'] as $refused) {
                self::assertArrayNotHasKey($refused, $schema, $path);
            }
            $type = $schema['type'] ?? null;
            self::assertIsString($type, $path . ' has one type');
            if ($type === 'object') {
                $properties = $schema['properties'] ?? null;
                self::assertIsArray($properties, $path);
                self::assertSame(array_keys($properties), $schema['required'] ?? null, $path . ' requires every key');
                self::assertFalse($schema['additionalProperties'] ?? null, $path);
                foreach ($properties as $name => $property) {
                    self::assertIsArray($property);
                    $walk($property, $path . '.' . $name);
                }
            }
            if ($type === 'array') {
                $items = $schema['items'] ?? null;
                self::assertIsArray($items, $path);
                $walk($items, $path . '[]');
            }
        };
        $walk(ScanSchema::flat(), 'flat');

        $flat = ScanSchema::flat()['properties'] ?? null;
        $full = ScanSchema::schema()['properties'] ?? null;
        self::assertIsArray($flat);
        self::assertIsArray($full);
        foreach (['fields', 'lines'] as $part) {
            $flatPart = $flat[$part] ?? null;
            $fullPart = $full[$part] ?? null;
            self::assertIsArray($flatPart);
            self::assertIsArray($fullPart);
            self::assertIsArray($flatPart['properties'] ?? null);
            self::assertIsArray($fullPart['properties'] ?? null);
            self::assertSame(array_keys($fullPart['properties']), array_keys($flatPart['properties']), $part);
        }
    }

    public function testABlankFlatAnswerReadsAsTheSchemaHasIt(): void
    {
        $fields = array_fill_keys(array_keys(ScanSchema::FIELDS), ['value' => '', 'evidence' => '']);
        $fields['total'] = ['value' => '58.53', 'evidence' => ''];
        $lines = array_fill_keys(array_keys(ScanSchema::LINES), []);
        $lines['work'] = ['Oil change', ''];
        $lines['recommendations'] = [
            ['text' => 'Front pads', 'distance' => '5,000', 'distance_unit' => 'miles', 'date' => ''],
            ['text' => '', 'distance' => '', 'distance_unit' => '', 'date' => ''],
        ];

        $answer = StructuredOutput::unflatten(['kind' => 'service_invoice', 'fields' => $fields, 'lines' => $lines]);

        self::assertSame([], SchemaCheck::errors($answer, ScanSchema::schema(false)));
        $read = $answer['fields'] ?? null;
        $list = $answer['lines'] ?? null;
        self::assertIsArray($read);
        self::assertIsArray($list);
        self::assertSame(['value' => '58.53', 'evidence' => null], $read['total'] ?? null);
        self::assertArrayHasKey('date', $read);
        self::assertNull($read['date'], 'a field not shown is null');
        self::assertArrayHasKey('advisories', $list);
        self::assertNull($list['advisories'], 'an empty list is null');
        self::assertSame(['Oil change'], $list['work'] ?? null);
        self::assertSame(
            [['text' => 'Front pads', 'distance' => '5,000', 'distance_unit' => 'miles', 'date' => null]],
            $list['recommendations'] ?? null,
        );
        $extraction = Extraction::fromObject($answer);
        self::assertSame('58.53', $extraction->value('total'));
        self::assertNull($extraction->value('date'));
    }

    public function testALimitTheFlatFormDropsIsStillCheckedOnTheAnswer(): void
    {
        $fields = array_fill_keys(array_keys(ScanSchema::FIELDS), ['value' => '', 'evidence' => '']);
        $fields['vendor'] = ['value' => str_repeat('x', ScanSchema::MAX_TEXT + 1), 'evidence' => ''];
        $answer = StructuredOutput::unflatten(['kind' => 'other', 'fields' => $fields, 'lines' => []]);

        self::assertNotSame([], SchemaCheck::errors($answer, ScanSchema::schema(false)));
    }
}
