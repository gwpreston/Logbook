<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Attention\AttentionList;
use Logbook\Service\Attention\AttentionWording;
use Logbook\Tests\Support\JsonDoc;

/**
 * `needs_attention` (spec.md §7.26): the current *Needs attention* items.
 */
final class NeedsAttentionTest extends ToolsBTestCase
{
    public function testTheItemsAreThoseOfTheOverview(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->vehicle($app);
        $this->vehicle($app, 'Volkswagen', 'Polo');
        $this->document($app, $golf, ComplianceType::Insurance, '2025-10-01', '2026-09-30', '290.00');
        $this->assertSchemaAccepts($app, $owner, 'needs_attention', ['vehicles' => [$golf->id]]);

        $result = $this->toolResult($app, $owner, 'needs_attention', ['vehicles' => [$golf->id]]);
        $data = new JsonDoc($result->data);
        $expected = $this->service($app, AttentionList::class)->forVehicles($owner, [$golf], sync: false)->items;
        $wording = $this->service($app, AttentionWording::class);

        self::assertNotSame([], $expected);
        self::assertSame(count($expected), $data->int('total_count'));
        self::assertSame($wording->title($expected[0]), $data->get('items', 0, 'title'));
        self::assertSame('now', $data->get('items', 0, 'severity'));
        self::assertSame('/vehicles/' . $golf->id, $result->link);
        self::assertSame('Needs attention · Volkswagen Golf', $result->source);
    }

    public function testWithoutVehiclesTheFleetIsCheckedAndOthersVehiclesAreNotFound(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->vehicle($app);
        $partner = $this->createMember($app);

        $result = $this->toolResult($app, $owner, 'needs_attention');
        self::assertSame('/garage', $result->link);
        self::assertSame([$golf->id], $result->vehicleIds);

        self::assertSame(ToolKit::NOT_FOUND, $this->call($app, $partner, 'needs_attention', ['vehicles' => [$golf->id]])->error);
        self::assertSame(0, $this->data($app, $partner, 'needs_attention')->int('total_count'));
    }
}
