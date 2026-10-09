<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\MotHistory;

use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MotTestRepository;
use Logbook\Repository\UserRepository;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
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
    use ApiFixtures;

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testTheApiReadsTheStoredTestsWithTheAttribution(): void
    {
        $this->start();
        $golf = $this->golf();
        $api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
        $path = '/vehicles/' . $golf->id . '/mot-tests';

        $empty = ApiClient::json($api->get($path));
        self::assertFalse($empty->get('enabled'));
        self::assertSame([], $empty->get('items'));

        $this->fetch($golf);
        $this->requests = [];
        $response = $api->get($path);
        self::assertSame(200, $response->getStatusCode());
        $body = ApiClient::json($response);
        self::assertSame([], $this->requests, 'never fetches');
        self::assertSame('yes', $body->get('recall'));
        self::assertCount(5, $body->column('id', 'items'));
        self::assertSame('passed', $body->get('items', 0, 'result'));
        self::assertSame('mi', $body->get('items', 0, 'tested_in'));
        self::assertStringContainsString('Open Government Licence', $body->string('provider', 'attribution'));

        $this->shareWith($golf, ShareLevel::View);
        $member = $this->service($this->app, UserRepository::class)->findByUsername('partner');
        self::assertNotNull($member);
        self::assertSame(200, $this->api($this->app, $this->apiKey($this->app, $member))->get($path)->getStatusCode());

        // No access: a 404, as every vehicle route; the CSV needs Manage.
        $this->createMember($this->app, 'stranger');
        $stranger = $this->service($this->app, UserRepository::class)->findByUsername('stranger');
        self::assertNotNull($stranger);
        self::assertSame(404, $this->api($this->app, $this->apiKey($this->app, $stranger))->get($path)->getStatusCode());
        $logger = $this->shareWith($golf, ShareLevel::Log, 'logger');
        self::assertContains($logger->get('/vehicles/' . $golf->id . '/export/mot-tests.csv')->getStatusCode(), [403, 404]);

        $this->service($this->app, MotHistoryConfig::class)->saveProvider(null);
        self::assertSame(404, $api->get($path)->getStatusCode());
    }

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
        // DVSA's lines were added by no one, even on a shared vehicle.
        $viewer = $this->shareWith($golf, ShareLevel::View);
        $shared = (string) $viewer->get('/vehicles/' . $golf->id . '/history?kind=documents')->getBody();
        self::assertStringContainsString('MOT passed', $shared);
        self::assertStringNotContainsString('added-by', $this->between($shared, 'MOT passed', '</a>'));
        // Not in Recent activity, which shows no attribution.
        self::assertSame([], array_filter(
            $this->feed()->latest($this->owner, [$golf], 50),
            static fn (ActivityItem $item): bool => $item->kind === ActivityKind::MotTest,
        ));
        self::assertStringContainsString('MOT passed', $page);
        self::assertStringContainsString('data-attribution', $page);
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

    public function testTheCsvHasOneRowPerDefect(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $path = '/vehicles/' . $golf->id . '/export/mot-tests.csv';

        $page = (string) $browser->get('/vehicles/' . $golf->id . '/mot-history')->getBody();
        self::assertStringContainsString($path, $page);
        $response = $browser->get($path);
        self::assertSame(200, $response->getStatusCode());
        $lines = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_values(array_filter(explode("\n", ltrim((string) $response->getBody(), "\xEF\xBB\xBF")))),
        );
        self::assertSame(
            ['Date and time (Europe/London)', 'Test number', 'Result', 'Expires', 'Odometer (Miles)', 'Tested in',
                'Defect type', 'Defect', 'Dangerous'],
            array_map(static fn (?string $cell): string => trim((string) $cell), $lines[0]),
        );
        $tests = $this->service($this->app, MotTestRepository::class)->listForVehicle($golf->id);
        $expected = 0;
        foreach ($tests as $test) {
            $expected += max(1, count($test->defects));
        }
        self::assertCount($expected + 1, $lines);
        self::assertSame('323456789012', $lines[1][1]);
        self::assertStringNotContainsString('Open Government', (string) $response->getBody(), '#341');

        $this->service($this->app, MotHistoryConfig::class)->saveProvider(null);
        self::assertSame(404, $browser->get($path)->getStatusCode());
    }

    public function testTheSalePackSummarisesTheTestsWithTheAttribution(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $path = '/vehicles/' . $golf->id . '/sale-pack';

        $pack = (string) $browser->get($path)->getBody();
        self::assertStringContainsString('data-sale-pack-mot', $pack);
        self::assertMatchesRegularExpression('/15 Feb 2026 · Passed · 43,961(\.\d+)? mi/u', $pack);
        self::assertStringContainsString('Odometer not read', $pack);
        self::assertStringContainsString('Open Government Licence', $pack);
        self::assertStringContainsString('gov.uk/check-mot-history', $pack, 'the printed DVSA link stays');
        self::assertStringNotContainsString('history-row', $this->between($pack, 'data-sale-pack-mot', '</dd>'));

        $this->service($this->app, MotHistoryConfig::class)->saveProvider(null);
        self::assertStringNotContainsString('data-sale-pack-mot', (string) $browser->get($path)->getBody());
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        self::assertNotFalse($start);
        $end = strpos($html, $to, $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
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
