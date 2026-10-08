<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Issue;

use Logbook\Domain\Attention\AttentionKind;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\User\User;
use Logbook\Repository\IssueRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Attention\AttentionList;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;

/**
 * The sample data (db/seeds/DemoDataSeeder.php, Phase 40.1): the Golf has
 * one open issue (a knock, with its reading and a note), one watched
 * advisory to look at again in three months, and one fixed by the June
 * brake pads.
 */
final class DemoIssuesTest extends AppTestCase
{
    public function testTheGolfHasAnOpenAWatchedAndAFixedIssue(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-10-06T10:00:00Z');
        $this->resetDatabase($app);
        Migrator::run('seed:run', ['--seed' => ['DemoDataSeeder']]);
        $demo = $this->service($app, UserRepository::class)->findByUsername('demo');
        self::assertInstanceOf(User::class, $demo);
        $golf = null;
        foreach ($this->service($app, VehicleService::class)->listFleet($demo, true) as $vehicle) {
            if ($vehicle->data->registration === 'LB19 KTR') {
                $golf = $vehicle;
            }
        }
        self::assertNotNull($golf);

        $issues = $this->service($app, IssueRepository::class)->listForVehicle($golf->id);
        $byStatus = [];
        foreach ($issues as $issue) {
            $byStatus[$issue->status()->value][] = $issue;
        }
        self::assertCount(1, $byStatus['open'] ?? []);
        // Phase 41: the MOT's tyre advisory is watched too (#335).
        $byStatus['watching'] = array_values(array_filter(
            $byStatus['watching'] ?? [],
            static fn ($issue): bool => $issue->source->value === 'manual',
        ));
        self::assertCount(1, $byStatus['watching']);
        self::assertCount(1, $byStatus['fixed'] ?? []);
        $knock = $byStatus['open'][0] ?? null;
        $pipes = $byStatus['watching'][0] ?? null;
        $grinding = $byStatus['fixed'][0] ?? null;
        self::assertNotNull($knock);
        self::assertNotNull($pipes);
        self::assertNotNull($grinding);

        self::assertNotNull($this->service($app, OdometerReadingRepository::class)
            ->findByEntry($golf->id, OdometerSource::Issue, $knock->id), 'the knock\'s mileage is in the log');
        self::assertCount(1, $this->service($app, IssueService::class)->updatesOf($knock));

        self::assertSame('2027-01-04', $pipes->data->lookAgainOn?->format('Y-m-d'));
        $fixes = $this->service($app, IssueService::class)->fixesOf($golf, $grinding);
        self::assertSame(['Front brake pads'], array_map(static fn ($r) => $r->data->title, $fixes));
        self::assertSame(IssueStatus::Open, $grinding->statusBeforeFix);

        $kinds = array_map(
            static fn ($item) => $item->kind,
            $this->service($app, AttentionList::class)->forVehicles($demo, [$golf])->items,
        );
        self::assertContains(AttentionKind::IssueOpen, $kinds);
        self::assertNotContains(AttentionKind::IssueLookAgain, $kinds, 'not for three months');
    }
}
