<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Household;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Ai\Provider\ToolCall;
use Logbook\Service\Reminder\CalendarFeed;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Household\Household;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\Household\LeakSweeping;
use Logbook\Tests\Support\MutableClock;
use Logbook\Tests\Support\TestBrowser;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;
use ZipArchive;

/**
 * One journey per person of the household (Phase 45.2, spec.md §5,
 * §7.21), through the pages as they would use them, signed in: what they
 * can do, and what they can't, as their share changes under them.
 */
final class HouseholdJourneysTest extends AppTestCase
{
    use ApiFixtures;
    use LeakSweeping;

    /** @var App<ContainerInterface> */
    private App $app;
    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->household = $this->loadHousehold($this->app);
    }

    public function testTheOwnerSharesChangesALevelTransfersKeepingAccessAndArchives(): void
    {
        $alex = $this->browserFor($this->app, 'alex');
        $adm = $this->browserFor($this->app, 'adm');
        $ev = $this->url('ev');

        self::assertSame(404, $adm->get($ev)->getStatusCode(), 'not before it is shared');
        $alex->get($ev . '/sharing');
        $added = $alex->post($ev . '/sharing', ['username' => 'adm', 'level' => 'view']);
        self::assertSame(303, $added->getStatusCode(), self::body($added));
        self::assertSame(200, $adm->get($ev)->getStatusCode());
        self::assertSame(403, $adm->get($ev . '/ownership')->getStatusCode(), 'View without costs');
        self::assertSame(403, $adm->get($ev . '/edit')->getStatusCode());

        $alex->get($ev . '/sharing');
        $member = $this->household->user('adm')->id;
        $changed = $alex->post($ev . '/sharing/' . $member . '/save', ['level' => 'manage', 'can_see_costs' => '1']);
        self::assertSame(303, $changed->getStatusCode(), self::body($changed));
        self::assertSame(200, $adm->get($ev . '/ownership')->getStatusCode(), 'costs, now');
        self::assertSame(200, $adm->get($ev . '/edit')->getStatusCode(), 'Manage edits');
        self::assertSame(403, $adm->get($ev . '/transfer')->getStatusCode(), 'but only the owner transfers');

        // The Golf goes to Jo; Alex keeps a Manage share, Sam keeps theirs, and the entries stay.
        $golf = $this->url('golf');
        $entries = $this->fuelCount('golf');
        $alex->get($golf . '/transfer');
        $moved = $alex->post($golf . '/transfer', ['username' => 'jo', 'keep_access' => '1']);
        self::assertSame(303, $moved->getStatusCode(), self::body($moved));
        $jo = $this->browserFor($this->app, 'jo');
        self::assertSame(200, $jo->get($golf . '/transfer')->getStatusCode(), 'Jo owns it');
        self::assertSame(403, $alex->get($golf . '/transfer')->getStatusCode(), 'Alex does not');
        self::assertSame(200, $alex->get($golf . '/edit')->getStatusCode(), 'Alex keeps Manage');
        self::assertSame(200, $this->browserFor($this->app, 'sam')->get($golf . '/fuel')->getStatusCode(), 'Sam keeps Log');
        self::assertSame($entries, $this->fuelCount('golf'));
        $shares = $this->service($this->app, VehicleShareRepository::class)
            ->listForVehicle($this->household->vehicle('golf')->id);
        $sharedWith = array_map(static fn ($s): int => $s->userId, $shares);
        self::assertNotContains($this->household->user('jo')->id, $sharedWith, 'the new owner has no share');

        // Archiving the EV: it leaves the garage's active list, and nothing more can be logged on it.
        $alex->get($ev);
        $archived = $alex->post($ev . '/archive');
        self::assertSame(303, $archived->getStatusCode(), self::body($archived));
        $api = new ApiClient($this->app, $this->apiKey($this->app, $this->household->user('alex')));
        self::assertSame(409, $api->post('/vehicles/' . $this->household->vehicle('ev')->id . '/expenses', [
            'category' => 'parking',
            'amount' => '2',
        ])->getStatusCode());
        self::assertSame(200, $this->browserFor($this->app, 'vic')->get($ev)->getStatusCode(), 'Vic still sees it');
    }

    public function testTheManagePartnerEditsOthersEntriesImportsPreparesASaleAndExports(): void
    {
        $jo = $this->browserFor($this->app, 'jo');
        $golf = $this->url('golf');
        $samsEntry = $this->lastFuel('golf', 'sam');
        $alexsEntry = $this->lastFuel('golf', 'alex');

        foreach ([$samsEntry, $alexsEntry] as $entry) {
            self::assertSame(200, $jo->get($golf . '/fuel/' . $entry . '/edit')->getStatusCode(), 'Manage edits anyone\'s');
        }
        $jo->get($golf . '/fuel/' . $alexsEntry . '/edit');
        $page = self::body($jo->get($golf . '/fuel/' . $alexsEntry . '/edit'));
        self::assertMatchesRegularExpression('/name="total"[^>]*value="[0-9.]+"/', $page, 'with its amount (costs)');

        // Import two expenses from a CSV, through the mapping and preview.
        $before = count(self::csvRows(self::body($jo->get($golf . '/export/expenses.csv'))));
        $jo->get($golf . '/import/expenses');
        $file = $this->csv("Date,Amount\n2026-09-12,7.25\n2026-09-13,4.10\n");
        $upload = $jo->post($golf . '/import/expenses', [], ['file' => $file]);
        self::assertSame(303, $upload->getStatusCode());
        $this->completeImport($jo, $upload->getHeaderLine('Location'));
        $after = self::csvRows(self::body($jo->get($golf . '/export/expenses.csv')));
        self::assertCount($before + 2, $after, 'imported, and in the CSV export');

        $pack = $jo->get($golf . '/sale-pack');
        self::assertSame(200, $pack->getStatusCode());
        self::assertStringContainsString('YH20 KLM', self::body($pack));
        foreach (['ev', 'focus', 'corolla'] as $other) {
            self::assertSame(404, $jo->get($this->url($other))->getStatusCode(), "{$other} is not Jo's to see");
        }
        self::assertSame(403, $jo->get($golf . '/transfer')->getStatusCode(), 'Manage does not transfer');
    }

    public function testTheLogDriverWithoutCostsLogsAMonthAndSeesOnlyTheAmountsTheyTyped(): void
    {
        $clock = $this->service($this->app, ClockInterface::class);
        self::assertInstanceOf(MutableClock::class, $clock);
        $clock->set(new DateTimeImmutable('2026-10-14T18:00:00Z'));
        $sam = $this->browserFor($this->app, 'sam');
        $golf = $this->url('golf');

        $totals = ['61.87', '63.42', '59.18', '64.03'];
        foreach (['2026-09-18', '2026-09-27', '2026-10-04', '2026-10-12'] as $i => $day) {
            $sam->get($golf . '/fuel/new');
            $saved = $sam->post($golf . '/fuel/new', [
                'filled_at' => $day . 'T08:30',
                'odometer' => (string) (105900 + 600 * $i),
                'fuel' => 'petrol',
                'volume' => '42.5',
                'price' => '',
                'total' => $totals[$i],
                'station' => '',
                'notes' => '',
            ]);
            self::assertSame(303, $saved->getStatusCode(), self::body($saved));
        }
        $sam->get($golf . '/maintenance/new');
        $service = $sam->post($golf . '/maintenance/new', [
            'performed_on' => '2026-10-06',
            'odometer' => '107100',
            'category' => 'oil',
            'title' => 'Top-up oil',
            'cost' => '23.99',
            'vendor' => '',
            'description' => '',
            'schedule' => '',
        ]);
        self::assertSame(303, $service->getStatusCode(), self::body($service));

        $fuel = self::body($sam->get($golf . '/fuel'));
        foreach ($totals as $total) {
            self::assertStringContainsString('£' . $total, $fuel, 'the amounts Sam typed');
        }
        $alexsTotal = $this->fuelTotal($this->lastFuel('golf', 'alex', before: '2026-09-01'));
        self::assertStringNotContainsString('£' . $alexsTotal, $fuel, 'not Alex\'s');
        self::assertStringContainsString('£23.99', self::body($sam->get($golf . '/maintenance')));
        foreach (['/ownership', '/valuations', '/finance', '/export/fuel.csv'] as $hidden) {
            self::assertContains($sam->get($golf . $hidden)->getStatusCode(), [403, 404], $hidden);
        }

        // Their own entries are theirs to edit; Alex's are not.
        self::assertSame(200, $sam->get($golf . '/fuel/' . $this->lastFuel('golf', 'sam') . '/edit')->getStatusCode());
        self::assertSame(403, $sam->get($golf . '/fuel/' . $this->lastFuel('golf', 'alex') . '/edit')->getStatusCode());
        self::assertSame(403, $sam->post($golf . '/fuel/' . $this->lastFuel('golf', 'alex') . '/delete')->getStatusCode());
    }

    public function testTheViewerReadsWhatTheLevelAllowsAndChangesNothing(): void
    {
        $vic = $this->browserFor($this->app, 'vic');
        $ev = $this->url('ev');
        $tabs = ['', '/fuel', '/odometer', '/maintenance', '/documents', '/expenses', '/ownership', '/valuations', '/history'];
        foreach ($tabs as $tab) {
            self::assertSame(200, $vic->get($ev . $tab)->getStatusCode(), 'reads ' . ($tab === '' ? 'the vehicle' : $tab));
        }
        $documents = self::body($vic->get($ev . '/documents'));
        $attachment = $this->attachmentOf('ev');
        if ($attachment !== null) {
            self::assertSame(200, $vic->get($ev . '/attachments/' . $attachment)->getStatusCode(), 'and its files');
        }
        self::assertStringNotContainsString('/documents/new', $documents, 'no add button');
        foreach (['/fuel/new', '/odometer/new', '/edit', '/sale-pack', '/export/fuel.csv'] as $change) {
            self::assertSame(403, $vic->get($ev . $change)->getStatusCode(), $change);
        }
        $vic->get($ev . '/fuel');
        self::assertSame(403, $vic->post($ev . '/fuel/new', ['total' => '1'])->getStatusCode());
        foreach (['golf', 'focus', 'corolla'] as $other) {
            self::assertSame(404, $vic->get($this->url($other))->getStatusCode(), "{$other} is not Vic's to see");
        }
    }

    public function testTheAdminWhoOwnsNothingSeesNoVehicleButBacksUpEverything(): void
    {
        $adm = $this->browserFor($this->app, 'adm');
        $garage = self::body($adm->get('/garage'));
        foreach ($this->household->vehicles() as $key => $vehicle) {
            self::assertSame(404, $adm->get($this->url($key))->getStatusCode(), $key);
        }
        self::assertStringNotContainsString('YH20 KLM', $garage);

        $backup = $adm->get('/settings/backup/download');
        self::assertSame(200, $backup->getStatusCode());
        $file = (string) tempnam(sys_get_temp_dir(), 'logbook-household-');
        file_put_contents($file, self::body($backup));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        /** @var array{tables: array<string, int>, files: int} $manifest */
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();
        unlink($file);
        self::assertSame(4, $manifest['tables']['vehicles'], 'every vehicle, whoever owns it');
        self::assertSame(7, $manifest['files'], 'and every file');
    }

    public function testASharedUserRevokedMidSessionLosesThePageTheApiTheFeedAndAsk(): void
    {
        $sam = $this->browserFor($this->app, 'sam');
        $user = $this->household->user('sam');
        $api = new ApiClient($this->app, $this->apiKey($this->app, $user, ApiScope::Read));
        $token = $this->service($this->app, CalendarFeed::class)->issueToken($user);
        $golf = $this->household->vehicle('golf');
        self::assertSame(200, $sam->get($this->url('golf'))->getStatusCode());
        self::assertSame(200, $api->get('/vehicles/' . $golf->id)->getStatusCode());

        $alex = $this->browserFor($this->app, 'alex');
        $alex->get($this->url('golf') . '/sharing');
        $removed = $alex->post($this->url('golf') . '/sharing/' . $user->id . '/remove');
        self::assertSame(303, $removed->getStatusCode(), self::body($removed));

        self::assertSame(404, $sam->get($this->url('golf'))->getStatusCode(), 'the next page');
        self::assertSame(404, $sam->get($this->url('golf') . '/fuel')->getStatusCode());
        self::assertSame(404, $api->get('/vehicles/' . $golf->id)->getStatusCode(), 'the next API call');
        self::assertNotContains($golf->id, ApiClient::json($api->get('/vehicles?status=all'))->column('id', 'items'));
        $feed = self::body((new TestBrowser($this->app))->get('/calendar/' . $token . '.ics'));
        self::assertStringNotContainsString('Volkswagen Golf', $feed, 'the next calendar fetch');
        self::assertStringContainsString('BEGIN:VCALENDAR', $feed, 'the feed itself is Sam\'s and stays');
        $run = $this->service($this->app, ToolRegistry::class)
            ->run($user, new ToolCall('ask', 'vehicle_summary', ['vehicle' => $golf->id]));
        self::assertNull($run->result, 'the next Ask question');
        self::assertSame(200, $sam->get($this->url('corolla'))->getStatusCode(), 'their own vehicle is untouched');
    }

    public function testAFormerOwnerWithoutKeepAccessSeesNothingAndTheirEntriesStay(): void
    {
        $alex = $this->browserFor($this->app, 'alex');
        $ev = $this->url('ev');
        $entries = $this->fuelCount('ev');
        $authors = $this->fuelAuthors('ev');

        $alex->get($ev . '/transfer');
        $moved = $alex->post($ev . '/transfer', ['username' => 'vic']);
        self::assertSame(303, $moved->getStatusCode(), self::body($moved));

        self::assertSame(404, $alex->get($ev)->getStatusCode());
        self::assertSame(404, $alex->get($ev . '/fuel')->getStatusCode());
        self::assertStringNotContainsString('YC23 EVN', self::body($alex->get('/garage')));
        self::assertStringNotContainsString('YC23 EVN', self::body($alex->get('/reports')));
        $api = new ApiClient($this->app, $this->apiKey($this->app, $this->household->user('alex'), ApiScope::Read));
        self::assertSame(404, $api->get('/vehicles/' . $this->household->vehicle('ev')->id)->getStatusCode());

        self::assertSame($entries, $this->fuelCount('ev'), 'the entries stay with the vehicle');
        self::assertSame($authors, $this->fuelAuthors('ev'), 'still Alex\'s');
        $vic = $this->browserFor($this->app, 'vic');
        self::assertSame(200, $vic->get($ev . '/transfer')->getStatusCode(), 'Vic owns it now');
    }

    private function url(string $vehicle): string
    {
        return '/vehicles/' . $this->household->vehicle($vehicle)->id;
    }

    private function fuelCount(string $vehicle): int
    {
        return count($this->fuel($vehicle));
    }

    /**
     * @return list<FuelEntry>
     */
    private function fuel(string $vehicle): array
    {
        return $this->service($this->app, FuelEntryRepository::class)->listForVehicle($this->household->vehicle($vehicle)->id);
    }

    /**
     * @return array<int, int|null> entry id => who logged it
     */
    private function fuelAuthors(string $vehicle): array
    {
        $authors = [];
        foreach ($this->fuel($vehicle) as $entry) {
            $authors[$entry->id] = $entry->createdBy;
        }
        ksort($authors);

        return $authors;
    }

    /** The latest fill-up on a vehicle by one person (before a day, when given). */
    private function lastFuel(string $vehicle, string $by, ?string $before = null): int
    {
        $userId = $this->household->user($by)->id;
        $limit = $before === null ? null : new DateTimeImmutable($before, new DateTimeZone('UTC'));
        foreach ($this->fuel($vehicle) as $entry) {
            if ($entry->createdBy === $userId && ($limit === null || $entry->data->filledAt < $limit)) {
                return $entry->id;
            }
        }
        self::fail("No fill-up on {$vehicle} by {$by}");
    }

    private function fuelTotal(int $id): string
    {
        foreach ($this->fuel('golf') as $entry) {
            if ($entry->id === $id) {
                return number_format((float) $entry->data->totalCost, 2);
            }
        }
        self::fail("No fill-up {$id}");
    }

    private function attachmentOf(string $vehicle): ?int
    {
        $id = $this->connection($this->app)->createQueryBuilder()
            ->select('MIN(id)')->from('attachments')
            ->where('vehicle_id = :vehicle')->setParameter('vehicle', $this->household->vehicle($vehicle)->id)
            ->executeQuery()->fetchOne();

        return is_numeric($id) ? (int) $id : null;
    }

    private function csv(string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-import-');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'expenses.csv', 'text/csv', strlen($content), UPLOAD_ERR_OK);
    }

    /**
     * Through the import's mapping and preview to its *Import* button, as the forms do.
     */
    private function completeImport(TestBrowser $browser, string $location): void
    {
        $mapping = self::body($browser->get($location));
        $query = Html::formValues(
            Html::element(Html::document($mapping), 'form[method="get"]'),
        );
        $preview = self::body($browser->get($location . '?' . http_build_query($query)));
        $form = Html::element(
            Html::document($preview),
            'form[method="post"][action="' . $location . '"]',
        );
        $done = $browser->post($location, Html::formValues($form));
        self::assertSame(200, $done->getStatusCode());
        self::assertStringContainsString('2 rows were imported.', self::body($done));
    }

    /**
     * @return list<string> the data rows (header and total rows left out)
     */
    private static function csvRows(string $csv): array
    {
        $lines = array_values(array_filter(explode("\n", trim($csv)), static fn (string $l): bool => trim($l) !== ''));

        return array_slice($lines, 1);
    }
}
