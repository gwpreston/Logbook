<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\IssueRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The issue pages (spec.md §7.37): logging one, watching it, the timeline,
 * fixing it from the service record and from the issue, *It's back*,
 * access, archived vehicles and the module switched off.
 */
final class IssuesTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-29T10:00:00Z';

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function form(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Knock from front left under braking',
            'noticed_on' => '2026-08-12',
            'odometer' => '48120',
            'description' => 'Only when cold.',
            'category' => 'brakes',
            'status' => 'open',
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function only(App $app, Vehicle $vehicle): Issue
    {
        $issues = $this->service($app, IssueRepository::class)->listForVehicle($vehicle->id);
        self::assertCount(1, $issues);

        return $issues[0];
    }

    public function testLoggingAnIssueAddsAReadingAndShowsItsPage(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/issues';

        self::assertStringContainsString('/log/new/issue', self::body($browser->get('/log/new')));
        $tabs = self::body($browser->get('/vehicles/' . $golf->id . '/maintenance'));
        self::assertStringContainsString($base . '"', $tabs, 'the Issues tab');
        self::assertStringContainsString('name="title"', self::body($browser->get($base . '/new')));

        $response = $browser->post($base . '/new', self::form());
        self::assertSame(303, $response->getStatusCode());
        $issue = $this->only($app, $golf);
        self::assertStringEndsWith($base . '/' . $issue->id, $response->getHeaderLine('Location'));
        self::assertSame(IssueStatus::Open, $issue->status());
        $reading = $this->service($app, OdometerReadingRepository::class)
            ->findByEntry($golf->id, OdometerSource::Issue, $issue->id);
        self::assertNotNull($reading, 'the mileage joins the log');

        $page = self::body($browser->get($base . '/' . $issue->id));
        self::assertStringContainsString('Knock from front left under braking', $page);
        self::assertStringContainsString('Only when cold.', $page);
        self::assertStringContainsString('Mark fixed', $page);

        $list = self::body($browser->get($base));
        self::assertStringContainsString('Knock from front left under braking', $list);
        self::assertStringNotContainsString('Knock', self::body($browser->get($base . '?status=fixed')));
        self::assertStringContainsString('Knock', self::body($browser->get('/issues')));
        self::assertStringContainsString('Issue', self::body($browser->get('/vehicles/' . $golf->id . '/odometer')));
    }

    public function testValidation(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/issues';

        $future = $browser->post($base . '/new', self::form(['noticed_on' => '2026-10-01']));
        self::assertSame(422, $future->getStatusCode());
        self::assertStringContainsString("be after today", self::body($future));

        $long = $browser->post($base . '/new', self::form(['title' => str_repeat('x', 121)]));
        self::assertSame(422, $long->getStatusCode());

        $look = $browser->post($base . '/new', self::form(['look_again_on' => '2026-12-01']));
        self::assertSame(422, $look->getStatusCode(), 'a look-again point needs watching');

        $watching = $browser->post($base . '/new', self::form(['status' => 'watching', 'look_again_on' => '2026-12-01']));
        self::assertSame(303, $watching->getStatusCode());
        $issue = $this->only($app, $golf);
        self::assertSame('2026-12-01', $issue->data->lookAgainOn?->format('Y-m-d'));
    }

    public function testWatchUpdateLogTheRepairAndItsBack(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/issues';
        $browser->post($base . '/new', self::form());
        $issue = $this->only($app, $golf);
        $at = $base . '/' . $issue->id;

        self::assertStringContainsString('name="look_again_on"', self::body($browser->get($at . '/watch')));
        self::assertSame(422, $browser->post($at . '/watch', ['look_again_on' => '2026-01-01'])->getStatusCode());
        self::assertSame(303, $browser->post($at . '/watch', ['look_again_on' => '2026-12-01'])->getStatusCode());
        self::assertSame(IssueStatus::Watching, $this->only($app, $golf)->status());

        $empty = $browser->post($at . '/updates/new', ['noted_on' => '2026-09-01']);
        self::assertSame(422, $empty->getStatusCode(), 'a note or a change');
        self::assertSame(303, $browser->post($at . '/updates/new', [
            'noted_on' => '2026-09-01',
            'note' => 'Still knocking, worse when cold',
            'odometer' => '48500',
        ])->getStatusCode());
        $page = self::body($browser->get($at));
        self::assertStringContainsString('Still knocking, worse when cold', $page);
        self::assertStringContainsString('Watching', $page);

        // *Log the repair*: the form prefilled, the issue ticked under Fixes.
        self::assertStringContainsString('/maintenance/new?fixes=' . $issue->id, self::body($browser->get($at . '/fix')));
        $form = self::body($browser->get('/vehicles/' . $golf->id . '/maintenance/new?fixes=' . $issue->id));
        self::assertStringContainsString('value="Knock from front left under braking"', $form);
        self::assertMatchesRegularExpression('/name="fixes\[\]" value="' . $issue->id . '" checked/', $form);
        $browser->post('/vehicles/' . $golf->id . '/maintenance/new', [
            'category' => 'brakes',
            'title' => 'Front pads and discs',
            'performed_on' => '2026-09-20',
            'cost' => '240',
            'fixes_sent' => '1',
            'fixes' => [(string) $issue->id],
        ]);
        $fixed = $this->only($app, $golf);
        self::assertSame(IssueStatus::Fixed, $fixed->status());
        self::assertSame('2026-09-20', $fixed->fixedOn?->format('Y-m-d'));
        self::assertStringContainsString('Front pads and discs', self::body($browser->get($at)));

        self::assertSame(303, $browser->post($at . '/reopen', [])->getStatusCode());
        $back = $this->only($app, $golf);
        self::assertSame(IssueStatus::Open, $back->status());
        self::assertStringContainsString("It&#039;s back", self::body($browser->get($at)));
    }

    public function testUntickingOnTheRecordReopensAndAFormWithoutTheChecklistKeepsTheLink(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $browser->post('/vehicles/' . $golf->id . '/issues/new', self::form());
        $issue = $this->only($app, $golf);
        $record = [
            'category' => 'brakes',
            'title' => 'Pads',
            'performed_on' => '2026-09-20',
            'cost' => '0',
        ];
        $ticked = $record + ['fixes_sent' => '1', 'fixes' => [(string) $issue->id]];
        $browser->post('/vehicles/' . $golf->id . '/maintenance/new', $ticked);
        $entries = $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($golf->id);
        $edit = '/vehicles/' . $golf->id . '/maintenance/' . $entries[0]->id . '/edit';

        $form = self::body($browser->get($edit));
        self::assertMatchesRegularExpression('/name="fixes\[\]" value="' . $issue->id . '" checked/', $form);
        $browser->post($edit, $record);
        self::assertSame(IssueStatus::Fixed, $this->only($app, $golf)->status(), 'no checklist sent: links kept');

        $browser->post($edit, $record + ['fixes_sent' => '1']);
        self::assertSame(IssueStatus::Open, $this->only($app, $golf)->status(), 'unticked: reopened');
    }

    public function testAfterItsBackTheRecordFormShowsItUntickedAndSavingKeepsItOpen(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $browser->post('/vehicles/' . $golf->id . '/issues/new', self::form());
        $issue = $this->only($app, $golf);
        $record = ['category' => 'brakes', 'title' => 'Pads', 'performed_on' => '2026-09-20', 'cost' => '0'];
        $ticked = $record + ['fixes_sent' => '1', 'fixes' => [(string) $issue->id]];
        $browser->post('/vehicles/' . $golf->id . '/maintenance/new', $ticked);
        $at = '/vehicles/' . $golf->id . '/issues/' . $issue->id;
        $browser->post($at . '/reopen', []);
        $entries = $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($golf->id);
        $edit = '/vehicles/' . $golf->id . '/maintenance/' . $entries[0]->id . '/edit';

        $form = self::body($browser->get($edit));
        self::assertMatchesRegularExpression('/name="fixes\[\]" value="' . $issue->id . '">/', $form, 'unticked');
        $browser->post($edit, $record + ['fixes_sent' => '1']);
        self::assertSame(IssueStatus::Open, $this->only($app, $golf)->status());

        // Watch and a status change are refused on a fixed issue.
        $browser->post($at . '/fix', ['how' => 'none', 'fixed_on' => '2026-09-25']);
        self::assertSame(303, $browser->post($at . '/watch', [])->getStatusCode());
        self::assertSame(IssueStatus::Fixed, $this->only($app, $golf)->status());
        $change = $browser->post($at . '/updates/new', ['noted_on' => '2026-09-26', 'note' => 'x', 'status' => 'open']);
        self::assertSame(422, $change->getStatusCode());
    }

    public function testLinkAnExistingRecordAndFixedWithoutARecord(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/issues';
        $browser->post($base . '/new', self::form());
        $browser->post($base . '/new', self::form(['title' => 'Rattle from the dash']));
        [$rattle, $knock] = $this->service($app, IssueRepository::class)->listForVehicle($golf->id);
        $old = $this->maintenance($app, $golf, '2026-08-01', 'Before it was noticed', '0');
        $after = $this->maintenance($app, $golf, '2026-09-01', 'Brakes', '120');

        $picker = self::body($browser->get($base . '/' . $knock->id . '/fix'));
        self::assertStringContainsString('Brakes', $picker);
        self::assertStringNotContainsString('Before it was noticed', $picker);
        $fix = $base . '/' . $knock->id . '/fix';
        self::assertSame(422, $browser->post($fix, ['how' => 'link', 'record' => (string) $old->id])->getStatusCode());
        self::assertSame(303, $browser->post($fix, ['how' => 'link', 'record' => (string) $after->id])->getStatusCode());
        $fixed = $this->service($app, IssueRepository::class)->find($golf->id, $knock->id);
        self::assertSame('2026-09-01', $fixed?->fixedOn?->format('Y-m-d'));

        self::assertSame(303, $browser->post($base . '/' . $rattle->id . '/fix', [
            'how' => 'none',
            'fixed_on' => '2026-09-10',
            'note' => 'Went away on its own',
        ])->getStatusCode());
        $page = self::body($browser->get($base . '/' . $rattle->id));
        self::assertStringContainsString('Went away on its own', $page);
        self::assertStringContainsString('Fixed without a service record.', $page);
    }

    public function testAViewShareSeesButCannotChange(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/issues';
        $browser->post($base . '/new', self::form());
        $issue = $this->only($app, $golf);
        $viewer = $this->createMember($app, 'viewer');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $viewer->id, ShareLevel::View, true, false, new DateTimeImmutable(self::NOW));
        $theirs = $this->browserFor($app, 'viewer');

        $page = self::body($theirs->get($base . '/' . $issue->id));
        self::assertStringContainsString('Knock from front left', $page);
        self::assertStringNotContainsString('Mark fixed', $page);
        $id = '/' . $issue->id;
        foreach (['/new', $id . '/edit', $id . '/watch', $id . '/fix', $id . '/updates/new'] as $path) {
            self::assertContains($theirs->get($base . $path)->getStatusCode(), [403, 404], $path);
        }
        self::assertContains($theirs->post($base . '/' . $issue->id . '/reopen', [])->getStatusCode(), [403, 404]);
    }

    public function testALogShareEditsOnlyTheirOwn(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/issues';
        $browser->post($base . '/new', self::form());
        $owners = $this->only($app, $golf);
        $member = $this->createMember($app, 'driver');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, true, false, new DateTimeImmutable(self::NOW));
        $theirs = $this->browserFor($app, 'driver');

        self::assertSame(303, $theirs->post($base . '/new', self::form(['title' => 'Slow leak, rear right']))->getStatusCode());
        self::assertSame(403, $theirs->get($base . '/' . $owners->id . '/edit')->getStatusCode());
        self::assertSame(303, $theirs->post($base . '/' . $owners->id . '/watch', [])->getStatusCode(), 'Log may watch');
    }

    public function testAnArchivedVehicleIsReadOnly(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/issues';
        $browser->post($base . '/new', self::form());
        $issue = $this->only($app, $golf);
        $this->service($app, VehicleService::class)->archive($this->owner($app), $golf);

        self::assertSame(200, $browser->get($base . '/' . $issue->id)->getStatusCode());
        self::assertStringNotContainsString('Mark fixed', self::body($browser->get($base . '/' . $issue->id)));
        self::assertSame(303, $browser->post($base . '/new', self::form())->getStatusCode());
        self::assertCount(1, $this->service($app, IssueRepository::class)->listForVehicle($golf->id), 'nothing added');
        self::assertStringNotContainsString('Knock', self::body($browser->get('/issues')), 'archived vehicles raise nothing');
    }

    public function testWorksBehindASubpath(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $created = $browser->post('/logbook/vehicles/' . $golf->id . '/issues/new', self::form());
        $issue = $this->only($app, $golf);
        self::assertSame('/logbook/vehicles/' . $golf->id . '/issues/' . $issue->id, $created->getHeaderLine('Location'));

        // Hard refresh with the prefix stripped by the proxy.
        $page = self::body($browser->get('/vehicles/' . $golf->id . '/issues/' . $issue->id));
        self::assertStringContainsString('href="/logbook/vehicles/' . $golf->id . '/issues/' . $issue->id . '/fix"', $page);
        self::assertStringContainsString('href="/logbook/vehicles/' . $golf->id . '/issues"', $page);
        $fleet = self::body($browser->get('/issues'));
        self::assertStringContainsString('href="/logbook/vehicles/' . $golf->id . '/issues/' . $issue->id . '"', $fleet);
        $watched = $browser->post('/logbook/vehicles/' . $golf->id . '/issues/' . $issue->id . '/watch', []);
        self::assertSame('/logbook/vehicles/' . $golf->id . '/issues/' . $issue->id, $watched->getHeaderLine('Location'));
    }

    public function testTheModuleSwitchedOffIs404Everywhere(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/issues';
        $browser->post($base . '/new', self::form());
        $issue = $this->only($app, $golf);

        $toggles = $this->service($app, FeatureToggles::class);
        $others = array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Issues);
        $toggles->save(array_values($others));

        foreach (['', '/new', '/' . $issue->id, '/' . $issue->id . '/edit', '/' . $issue->id . '/fix'] as $path) {
            self::assertSame(404, $browser->get($base . $path)->getStatusCode(), $path);
        }
        self::assertSame(404, $browser->get('/issues')->getStatusCode());
        self::assertStringNotContainsString('/issues', self::body($browser->get('/vehicles/' . $golf->id)));
        self::assertStringNotContainsString('/log/new/issue', self::body($browser->get('/log/new')));
        $form = self::body($browser->get('/vehicles/' . $golf->id . '/maintenance/new'));
        self::assertStringNotContainsString('fixes_sent', $form);
    }
}
