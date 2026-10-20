<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Scan;

use Logbook\Service\Ai\Scan\Extraction;
use Logbook\Service\Ai\Scan\ScanSchema;
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

        foreach ($fields as $name => $field) {
            self::assertIsArray($field);
            self::assertSame(['value', 'evidence'], $field['required'] ?? null, (string) $name);
            self::assertSame(['object', 'null'], $field['type'] ?? null, (string) $name . ' may still be null');
        }
    }

    public function testAnAnswerWithoutEvidenceIsStillRead(): void
    {
        // Providers that don't enforce the schema may leave a key out; the answer isn't refused for it.
        $read = Extraction::fromObject(['kind' => 'fuel_receipt', 'fields' => ['total' => ['value' => '60.28']]]);

        self::assertSame('60.28', $read->value('total'));
    }
}
