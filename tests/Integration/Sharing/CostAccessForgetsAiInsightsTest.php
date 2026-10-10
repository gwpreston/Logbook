<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Sharing;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Ai\Insights\AiInsight;
use Logbook\Domain\Ai\Insights\AiInsightSet;
use Logbook\Repository\AiInsightRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Sharing\SharingService;
use Logbook\Tests\Support\BriefingTestCase;

/**
 * Losing cost access forgets the member's kept AI insights (spec.md
 * §7.26, #373): a set made while they could see a vehicle's costs may
 * quote them, and the Insights page and the monthly digest read it until
 * the next day's.
 */
final class CostAccessForgetsAiInsightsTest extends BriefingTestCase
{
    public function testTurningCostsOffForgetsTheMembersSet(): void
    {
        [$golf, $viewer] = $this->sharedWithKeptSet(ShareLevel::View, true);

        $this->service($this->app, SharingService::class)->update($golf, $viewer, ShareLevel::View, false, true);

        self::assertNull($this->service($this->app, AiInsightRepository::class)->find($viewer));
    }

    public function testOtherChangesKeepIt(): void
    {
        [$golf, $viewer] = $this->sharedWithKeptSet(ShareLevel::View, true);

        // Notify off, costs still on: nothing they saw has become hidden.
        $this->service($this->app, SharingService::class)->update($golf, $viewer, ShareLevel::Log, true, false);

        self::assertNotNull($this->service($this->app, AiInsightRepository::class)->find($viewer));
    }

    public function testASetMadeWithoutCostsIsKept(): void
    {
        [$golf, $viewer] = $this->sharedWithKeptSet(ShareLevel::View, false);

        $this->service($this->app, SharingService::class)->update($golf, $viewer, ShareLevel::View, false, false);

        self::assertNotNull($this->service($this->app, AiInsightRepository::class)->find($viewer));
    }

    /**
     * @return array{0: \Logbook\Domain\Vehicle\Vehicle, 1: int}
     */
    private function sharedWithKeptSet(ShareLevel $level, bool $costs): array
    {
        $this->start();
        $golf = $this->car();
        $this->createMember($this->app, 'viewer');
        self::assertNull($this->service($this->app, SharingService::class)->add($golf, 'viewer', $level, $costs, true));
        $viewer = $this->service($this->app, UserRepository::class)->findByUsername('viewer');
        self::assertNotNull($viewer);
        $this->service($this->app, AiInsightRepository::class)->save($viewer->id, new AiInsightSet(
            '2026-09-30',
            [new AiInsight('Insurance was £412.00', 'Most of the spend.', [], [], vehicles: [$golf->id])],
            [],
            null,
            null,
            null,
            null,
            new DateTimeImmutable(self::NOW),
        ));

        return [$golf, $viewer->id];
    }
}
