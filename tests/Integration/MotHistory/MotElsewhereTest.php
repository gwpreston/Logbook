<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\MotHistory;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\History\ActivityFeed;
use Logbook\Service\History\ActivityItem;
use Logbook\Service\History\ActivityKind;
use Logbook\Service\History\HistoryChip;
use Logbook\Service\History\PrintOptions;
use Logbook\Service\MotHistory\MotHistoryConfig;

/**
 * MOT history outside its own pages (spec.md §7.38 *Pages and elsewhere*):
 * History lists each test not carried by a document, never twice; print
 * leaves them out; nothing shows while MOT history is off.
 */
final class MotElsewhereTest extends MotHistoryTestCase
{
    public function testHistoryListsEachTestUnlessItBecameADocument(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);

        $lines = $this->motLines($golf, 2026);
        self::assertSame(['history.kind.mot_passed', 'history.kind.mot_failed'], array_map(
            static fn (ActivityItem $item): string => $item->labelKey,
            $lines,
        ));
        self::assertSame('2026-02-15', $lines[0]->date->format('Y-m-d'));
        self::assertNotNull($lines[0]->odometerKm);
        self::assertSame(2019, $this->feed()->year($this->owner, [$golf], HistoryChip::Documents->kinds(), 2019)->year);

        $page = (string) $browser->get('/vehicles/' . $golf->id . '/history?kind=documents')->getBody();
        self::assertStringContainsString('MOT passed', $page);
        self::assertMatchesRegularExpression('/MOT failed.*?\d+ defects or advisories/s', $page);
        self::assertStringContainsString('/vehicles/' . $golf->id . '/mot-history', $page);

        // Passes added as documents: their document lines carry them; the fail stays.
        $browser->post('/vehicles/' . $golf->id . '/mot-history/review', ['do' => 'documents']);
        $lines = $this->motLines($golf, 2026);
        $labels = array_map(static fn (ActivityItem $item): string => $item->labelKey, $lines);
        self::assertSame(['history.kind.mot_failed'], $labels);
        $documents = array_filter(
            $this->feed()->year($this->owner, [$golf], HistoryChip::Documents->kinds(), 2026)->items,
            static fn (ActivityItem $item): bool => $item->kind === ActivityKind::Document,
        );
        self::assertCount(1, $documents);

        // Off: no MOT lines at all.
        $this->service($this->app, MotHistoryConfig::class)->saveProvider(null);
        self::assertSame([], $this->motLines($golf, 2026));
    }

    public function testPrintLeavesThemOut(): void
    {
        self::assertNotContains(ActivityKind::MotTest, (new PrintOptions([HistoryChip::Documents], true))->kinds());
        self::assertContains(ActivityKind::MotTest, HistoryChip::Documents->kinds());
    }

    /**
     * @return list<ActivityItem>
     */
    private function motLines(Vehicle $vehicle, int $year): array
    {
        return array_values(array_filter(
            $this->feed()->year($this->owner, [$vehicle], HistoryChip::Documents->kinds(), $year)->items,
            static fn (ActivityItem $item): bool => $item->kind === ActivityKind::MotTest,
        ));
    }

    private function feed(): ActivityFeed
    {
        return $this->service($this->app, ActivityFeed::class);
    }
}
