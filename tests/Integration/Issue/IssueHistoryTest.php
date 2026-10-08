<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Issue;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueData;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Issues in History, the printable service history and the sale pack
 * (spec.md §7.37, §7.16, §7.19, #312): noticed and fixed lines under an
 * *Issues* chip; print shows fixed issues with their fix, never open ones;
 * the sale pack lists open ones only when the seller ticks it.
 */
final class IssueHistoryTest extends AppTestCase
{
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;
    private Vehicle $golf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->browser = $this->signedIn($this->app);
        $this->golf = $this->vehicle($this->app);
    }

    private static function on(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        assert($day !== null);

        return $day;
    }

    private function log(string $title, string $date, bool $safety = false): Issue
    {
        return $this->service($this->app, IssueService::class)->create($this->golf, new IssueData(
            noticedOn: self::on($date),
            title: $title,
            affectsSafety: $safety,
        ), new DateTimeZone('Europe/London'));
    }

    /**
     * A knock fixed by a brake job, and a steering pull still open.
     */
    private function story(): void
    {
        $knock = $this->log('Knock from front left under braking', '2026-08-12');
        $this->log('Steering pulls left', '2026-09-01', true);
        $this->service($this->app, MaintenanceService::class)->create(
            $this->golf,
            new MaintenanceEntryData(self::on('2026-09-20'), MaintenanceCategory::Brakes, 'Front pads and discs', '240.00'),
            new DateTimeZone('Europe/London'),
            fixes: [$knock->id],
        );
    }

    public function testHistoryListsNoticedAndFixedLinesUnderTheIssuesChip(): void
    {
        $this->story();
        $base = '/vehicles/' . $this->golf->id . '/history';

        $all = self::body($this->browser->get($base));
        self::assertStringContainsString('Issue noticed', $all);
        self::assertStringContainsString('Issue fixed', $all);
        self::assertStringContainsString('Front pads and discs (20 Sept 2026)', $all, 'the fix on its second line');
        self::assertStringContainsString('Affects safety', $all);
        self::assertStringContainsString('?kind=issues', $all, 'the chip');

        $chip = self::body($this->browser->get($base . '?kind=issues'));
        self::assertStringContainsString('Steering pulls left', $chip);
        self::assertStringNotContainsString('history-row--milestone', $chip);
    }

    public function testPrintShowsFixedIssuesWithTheirFixAndNeverOpenOnes(): void
    {
        $this->story();
        $print = self::body($this->browser->get('/vehicles/' . $this->golf->id . '/history/print'));

        self::assertStringContainsString('Knock from front left under braking', $print);
        self::assertStringContainsString('Issue fixed', $print);
        self::assertStringNotContainsString('Steering pulls left', $print);
        self::assertStringNotContainsString('Issue noticed', $print);
    }

    public function testTheSalePackListsOpenIssuesOnlyWhenTicked(): void
    {
        $this->story();
        $pack = '/vehicles/' . $this->golf->id . '/sale-pack';

        $default = self::body($this->browser->get($pack));
        self::assertStringContainsString('name="issues"', $default, 'the option is offered');
        self::assertStringNotContainsString('Steering pulls left', $default, 'off by default');

        $ticked = self::body($this->browser->get($pack . '?options=1&issues=1'));
        self::assertStringContainsString('Steering pulls left', $ticked);
        self::assertStringContainsString('Affects safety', $ticked);
        self::assertStringContainsString('Listed because you chose to include them.', $ticked);
    }
}
