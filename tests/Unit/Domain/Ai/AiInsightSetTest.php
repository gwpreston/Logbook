<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Domain\Ai;

use DateTimeImmutable;
use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Ai\Ask\ToolRun;
use Logbook\Domain\Ai\Insights\AiInsight;
use Logbook\Domain\Ai\Insights\AiInsightSet;
use Logbook\Domain\Ai\Insights\AiInsightTopic;
use PHPUnit\Framework\TestCase;

/**
 * A cached day of AI insights (spec.md §7.26 *AI insights*): what a user
 * may still see, and the stored shape read back defensively.
 */
final class AiInsightSetTest extends TestCase
{
    public function testAnInsightAboutAVehicleNoLongerVisibleIsDropped(): void
    {
        $golf = new ToolRun('1', 'costs', [], new ToolResult(['total' => '£1'], 'Costs'), null, [1]);
        $bmw = new ToolRun('2', 'mileage', [], new ToolResult(['km' => '1'], 'Mileage'), null, [2]);
        $set = new AiInsightSet(
            '2026-10-15',
            [
                new AiInsight('Golf', 'About the Golf.', [0]),
                new AiInsight('BMW', 'About the BMW.', [1]),
                new AiInsight('Both', 'Both.', [0, 1]),
            ],
            [$golf, $bmw],
            'Ollama',
            null,
            'llama',
            null,
            new DateTimeImmutable('2026-10-15T06:00:00Z'),
        );

        $visible = $set->visibleTo([1]);

        $titles = array_map(static fn (AiInsight $i): string => $i->title, $visible->insights);
        self::assertSame(['Golf'], $titles, 'a share revoked since the morning');
        self::assertSame([$golf], $visible->sourcesOf($visible->insights[0]));
        self::assertTrue($set->isFor('2026-10-15'));
        self::assertFalse($set->isFor('2026-10-16'));
    }

    public function testTheStoredShapeIsReadDefensively(): void
    {
        self::assertNull(AiInsight::fromArray(['title' => 'No body']));
        $insight = AiInsight::fromArray(['title' => 'T', 'body' => 'B', 'sources' => [0, '1', 2], 'ungrounded' => ['£9', 3]]);
        self::assertNotNull($insight);
        self::assertSame([0, 2], $insight->sources);
        self::assertSame(['£9'], $insight->ungrounded);
        self::assertSame(AiInsightTopic::Other, $insight->topic, 'a set from before Phase 42 has no topic');
        self::assertSame([], $insight->vehicles);
        self::assertSame(
            ['title' => 'T', 'body' => 'B', 'sources' => [0, 2], 'ungrounded' => ['£9'], 'topic' => 'other', 'vehicles' => []],
            $insight->toArray(),
        );

        $tagged = AiInsight::fromArray(['title' => 'T', 'body' => 'B', 'topic' => 'economy', 'vehicles' => [3, '4', 5]]);
        self::assertSame(AiInsightTopic::Economy, $tagged?->topic);
        self::assertSame([3, 5], $tagged->vehicles);
        $unknown = AiInsight::fromArray(['title' => 'T', 'body' => 'B', 'topic' => 'weather']);
        self::assertSame(AiInsightTopic::Other, $unknown?->topic);
    }

    public function testOnlyVisibleVehiclesAreKeptAndTheFilterKeepsWhatItAccepts(): void
    {
        $set = new AiInsightSet(
            '2026-10-15',
            [
                new AiInsight('Kept', 'B', topic: AiInsightTopic::FuelCost, vehicles: [1, 2]),
                new AiInsight('Unmatched', 'B', ungrounded: ['£9']),
            ],
            [],
            null,
            null,
            null,
            null,
            new DateTimeImmutable('2026-10-15T06:00:00Z'),
        );

        $visible = $set->visibleTo([1]);
        self::assertSame([1], $visible->insights[0]->vehicles, 'a vehicle no longer in sight is forgotten');
        $grounded = $visible->kept(static fn (AiInsight $i): bool => $i->isGrounded());
        self::assertSame(['Kept'], array_map(static fn (AiInsight $i): string => $i->title, $grounded->insights));
    }
}
