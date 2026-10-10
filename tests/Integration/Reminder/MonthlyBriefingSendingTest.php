<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Issue\IssueData;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\Notification\QuietHours;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Sharing\SharingService;
use Logbook\Tests\Support\BriefingTestCase;

/**
 * When the monthly briefing is sent and what it holds for whom (spec.md
 * §7.11 *The monthly briefing*, Phase 43.3): `ViewCosts`, open issues, the
 * *Include* choices (#360, #363) and quiet hours.
 */
final class MonthlyBriefingSendingTest extends BriefingTestCase
{
    public function testAViewShareWithoutViewCostsGetsTheDistanceButNoMoney(): void
    {
        $this->start();
        $golf = $this->car();
        $this->reading($golf, '10000', '2026-08-20');
        $this->reading($golf, '10400', '2026-09-10');
        $this->spend($golf, '2026-09-05', '30');
        $this->createMember($this->app, 'viewer');
        $sharing = $this->service($this->app, SharingService::class);
        self::assertNull($sharing->add($golf, 'viewer', ShareLevel::View, false, true));
        $this->browserFor($this->app, 'viewer')->get('/');
        $this->saveChannels($this->browserFor($this->app, 'viewer'), 'viewer', 'viewer@example.com');

        $this->runTasks();

        $viewer = $this->digestText('viewer@example.com');
        self::assertStringContainsString('• Volkswagen Golf: 249 mi driven', $viewer);
        self::assertStringNotContainsString('spent', $viewer);
        self::assertStringNotContainsString('running cost', $viewer);
        self::assertStringNotContainsString('£', $viewer);
        $row = self::row($this->digestJson('viewer'));
        self::assertSame('400.000', $row['distance']);
        foreach (['spend', 'spend_average', 'currency', 'cost_per_distance', 'cost_per_distance_average'] as $key) {
            self::assertArrayNotHasKey($key, $row);
        }
        self::assertStringNotContainsString('£', self::text($row['display']));

        $owner = $this->digestText();
        self::assertStringContainsString('£30.00 spent', $owner, 'the owner sees the money');
        self::assertArrayHasKey('spend', self::row($this->digestJson()));
    }

    public function testAViewShareWithCostsGetsTheSpend(): void
    {
        $this->start();
        $golf = $this->car();
        $this->spend($golf, '2026-09-05', '30');
        $this->createMember($this->app, 'viewer');
        $sharing = $this->service($this->app, SharingService::class);
        self::assertNull($sharing->add($golf, 'viewer', ShareLevel::View, true, true));
        $this->browserFor($this->app, 'viewer')->get('/');
        $this->saveChannels($this->browserFor($this->app, 'viewer'), 'viewer', 'viewer@example.com');

        $this->runTasks();

        self::assertStringContainsString('£30.00 spent', $this->digestText('viewer@example.com'));
    }

    public function testAViewRecipientWithNothingToSeeAndNoCostsGetsNoDigest(): void
    {
        $this->start();
        $golf = $this->car();
        $this->spend($golf, '2026-09-05', '30');
        $this->createMember($this->app, 'viewer');
        $sharing = $this->service($this->app, SharingService::class);
        self::assertNull($sharing->add($golf, 'viewer', ShareLevel::View, false, true));
        $this->browserFor($this->app, 'viewer')->get('/');
        $this->saveChannels($this->browserFor($this->app, 'viewer'), 'viewer', 'viewer@example.com');

        $this->runTasks();

        self::assertSame([], $this->digestMails('viewer@example.com'), 'no distance, and the money is hidden');
        self::assertCount(1, $this->digestMails('pat@example.com'));
    }

    public function testOpenIssuesAreAttentionLinesAndWatchingOnesAreNot(): void
    {
        $this->start();
        $golf = $this->car();
        $this->issue($golf, 'Rattle', IssueStatus::Open);
        $this->issue($golf, 'Squeak', IssueStatus::Open);
        $this->issue($golf, 'Noise on cold starts', IssueStatus::Watching);

        $this->runTasks();

        $text = $this->digestText();
        self::assertStringContainsString('• Volkswagen Golf: 2 open issues', $text);
        self::assertStringContainsString('needs attention', $text);
        $json = $this->digestJson();
        self::assertSame([['vehicle_id' => $golf->id, 'vehicle' => 'Volkswagen Golf', 'open' => 2]], $json['issues']);
    }

    public function testOneOpenIssueReadsInTheSingular(): void
    {
        $this->start();
        $this->issue($this->car(), 'Rattle', IssueStatus::Open);

        $this->runTasks();

        self::assertStringContainsString('• Volkswagen Golf: 1 open issue', $this->digestText());
    }

    public function testOnlyWatchingIssuesSendNothing(): void
    {
        $this->start();
        $this->issue($this->car(), 'Noise on cold starts', IssueStatus::Watching);

        $this->runTasks();

        self::assertStringNotContainsString('open issue', implode("\n", array_map(
            static fn ($m): string => (string) $m->getTextBody(),
            $this->digestMails(),
        )));
    }

    public function testOpenIssuesAreLeftOutWithTheIssuesModuleOff(): void
    {
        $this->start();
        $golf = $this->car();
        $this->issue($golf, 'Rattle', IssueStatus::Open);
        $this->document($this->app, $golf, '2026-10-20');
        $features = $this->service($this->app, FeatureToggles::class);
        $features->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Issues)));

        $this->runTasks();

        self::assertStringNotContainsString('open issue', $this->digestText());
        self::assertSame([], $this->digestJson()['issues']);
    }

    public function testOpenIssuesFollowTheNeedsAttentionBox(): void
    {
        $this->start();
        $golf = $this->car();
        $this->issue($golf, 'Rattle', IssueStatus::Open);
        $this->document($this->app, $golf, '2026-10-20');
        $this->saveChoice(['last_month', 'insights']);

        $this->runTasks();

        self::assertStringNotContainsString('open issue', $this->digestText());
        self::assertSame([], $this->digestJson()['issues']);
    }

    public function testNoStoredChoiceIncludesEverything(): void
    {
        $this->start();
        $golf = $this->car();
        $this->issue($golf, 'Rattle', IssueStatus::Open);
        $this->spend($golf, '2026-09-05', '30');
        self::assertNull($this->settings()->notificationPreferences($this->owner($this->app)->id)->digestSections);

        $this->runTasks();

        $text = $this->digestText();
        self::assertStringContainsString('open issue', $text);
        self::assertStringContainsString('Last month (September 2026):', $text);
    }

    public function testAnEmptyChoiceSendsOnlyWhatIsDue(): void
    {
        $this->start();
        $golf = $this->car();
        $this->issue($golf, 'Rattle', IssueStatus::Open);
        $this->spend($golf, '2026-09-05', '30');
        $this->document($this->app, $golf, '2026-10-20');
        $this->saveChoice([]);

        $this->runTasks();

        $text = $this->digestText();
        self::assertStringContainsString('Insurance — Volkswagen Golf', $text);
        self::assertStringNotContainsString('open issue', $text);
        self::assertStringNotContainsString('Last month', $text);
        $json = $this->digestJson();
        self::assertSame([], $json['last_month']);
        self::assertNull($json['fleet']);
        self::assertSame([], $json['issues']);
        self::assertSame([], $json['insights']);
    }

    public function testAMonthWithOnlyLastMonthFiguresSendsNothingWhenThatBoxIsUnticked(): void
    {
        $this->start();
        $golf = $this->car();
        $this->spend($golf, '2026-09-05', '30');
        $this->saveChoice(['attention', 'insights']);

        $this->runTasks();

        self::assertSame([], $this->digestMails());
        $month = $this->settings()->digestMonth($this->owner($this->app)->id);
        self::assertSame('2026-10', $month, 'nothing to send counts as done');
    }

    public function testAMonthWithOnlyLastMonthFiguresSendsWhenThatBoxIsTicked(): void
    {
        $this->start();
        $golf = $this->car();
        $this->spend($golf, '2026-09-05', '30');
        $this->saveChoice(['last_month']);

        $this->runTasks();

        $mails = $this->digestMails();
        self::assertCount(1, $mails);
        self::assertSame('October 2026: your monthly briefing', $mails[0]->getSubject());
        $text = (string) $mails[0]->getTextBody();
        self::assertStringStartsWith('Nothing is due in October 2026.', $text);
        self::assertStringContainsString('• Volkswagen Golf: £30.00 spent', $text);
    }

    public function testAMonthWithNothingAtAllSendsNothing(): void
    {
        $this->start();
        $this->car();

        $this->runTasks();

        self::assertSame([], $this->digestMails());
        self::assertSame('2026-10', $this->settings()->digestMonth($this->owner($this->app)->id));
    }

    public function testTheSectionsComeInOrder(): void
    {
        $this->start();
        $golf = $this->car();
        $this->document($this->app, $golf, '2026-10-20');
        $this->issue($golf, 'Rattle', IssueStatus::Open);
        $this->spend($golf, '2026-09-05', '30');

        $this->runTasks();

        $text = $this->digestText();
        $positions = [
            strpos($text, 'Insurance — Volkswagen Golf'),
            strpos($text, 'open issue'),
            strpos($text, 'Last month (September 2026):'),
            strpos($text, 'https://garage.example/reminders'),
        ];
        self::assertSame($positions, array_values(array_filter($positions, static fn ($p): bool => $p !== false)));
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'due, attention, last month, then the link');
    }

    public function testQuietHoursStillHoldTheDigestUntilTheyEnd(): void
    {
        $this->start();
        $golf = $this->car();
        $this->spend($golf, '2026-09-05', '30');
        $owner = $this->owner($this->app);
        $quiet = QuietHours::fromStored(['start' => '22:00', 'end' => '09:00']);
        $this->settings()->saveNotificationPreferences(
            $owner->id,
            $this->settings()->notificationPreferences($owner->id)->withQuiet($quiet),
        );

        $this->runTasks();

        self::assertSame([], $this->digestMails(), '08:00 in London is quiet');
        self::assertNull($this->settings()->digestMonth($owner->id), 'held, not done');

        $this->clock->set(new DateTimeImmutable('2026-10-01T09:30:00Z'));
        $this->runTasks();

        self::assertCount(1, $this->digestMails());
        self::assertSame('2026-10', $this->settings()->digestMonth($owner->id));
    }

    private function settings(): ReminderSettingsStore
    {
        return $this->service($this->app, ReminderSettingsStore::class);
    }

    /**
     * Save the digest's Include boxes as the settings page would.
     *
     * @param list<string> $sections
     */
    private function saveChoice(array $sections): void
    {
        $this->saveChannels($this->browser, 'owner', 'pat@example.com', [
            'digest_include_shown' => '1',
            'digest_include' => $sections,
        ]);
    }

    private function issue(Vehicle $vehicle, string $title, IssueStatus $status): void
    {
        $this->service($this->app, IssueService::class)->create(
            $vehicle,
            new IssueData(self::date('2026-09-15'), $title, $status),
            new DateTimeZone('Europe/London'),
        );
    }
}
