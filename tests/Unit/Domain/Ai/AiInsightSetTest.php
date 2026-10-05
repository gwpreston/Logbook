<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Domain\Ai;

use DateTimeImmutable;
use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Ai\Ask\ToolRun;
use Logbook\Domain\Ai\Insights\AiInsight;
use Logbook\Domain\Ai\Insights\AiInsightSet;
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
        self::assertSame(['title' => 'T', 'body' => 'B', 'sources' => [0, 2], 'ungrounded' => ['£9']], $insight->toArray());
    }
}
