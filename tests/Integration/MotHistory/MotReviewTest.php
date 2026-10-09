<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\MotHistory;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Repository\ReminderRepository;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueSource;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\MotHistory\MotDefect;
use Logbook\Domain\MotHistory\MotDefectType;
use Logbook\Domain\MotHistory\MotTest;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\IssueRepository;
use Logbook\Repository\MotTestRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Tests\Support\TestBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The review card (spec.md §7.38 *Review card*): passes as `inspection`
 * documents without duplicates, DVSA's first MOT due date offered and
 * never filled on its own, defects as issues with the decided statuses
 * (#324, #328, #333) and look-again points, repeats as updates, *Not now*
 * that sticks, and *Done*.
 */
final class MotReviewTest extends MotHistoryTestCase
{
    public function testPassesBecomeDocumentsOnceOldestFirstWithNoReadingOfTheirOwn(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);

        $page = (string) $browser->get($this->url($golf))->getBody();
        self::assertStringContainsString('Add all passes as documents (4)', $page);
        $browser->post($this->url($golf), ['do' => 'documents']);

        $documents = $this->service($this->app, ComplianceDocumentRepository::class)->listForVehicle($golf->id);
        self::assertCount(4, $documents);
        foreach ($documents as $document) {
            self::assertSame(ComplianceType::Inspection, $document->data->type);
            self::assertSame('DVSA MOT', $document->data->provider);
            self::assertSame('0.000', $document->data->cost);
            self::assertNull($document->data->odometerKm);
        }
        $references = array_map(static fn ($d): ?string => $d->data->reference, $documents);
        self::assertContains('323456789012', $references);
        self::assertContains(null, $references, 'the numberless test has no reference');
        $sources = array_map(
            static fn ($r): string => $r->source->value,
            $this->service($this->app, OdometerReadingRepository::class)->listForVehicle($golf->id),
        );
        self::assertNotContains(OdometerSource::Document->value, $sources, 'the mot reading is the reading');

        // Already logged: offered again neither on the card nor by a refresh.
        $this->fetch($golf, $browser);
        $browser->post($this->url($golf), ['do' => 'documents']);
        self::assertCount(4, $this->service($this->app, ComplianceDocumentRepository::class)->listForVehicle($golf->id));
    }

    public function testADocumentOnTheSameDayIsAlreadyLogged(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $latest = $this->tests($golf)[0];
        $browser->post('/vehicles/' . $golf->id . '/documents/new', [
            'type' => 'inspection',
            'start_on' => '2026-02-15',
            'expiry_on' => '2027-03-13',
            'cost' => '54.85',
        ]);

        $browser->post($this->url($golf), ['do' => 'document', 'test' => (string) $latest->id]);

        $documents = $this->service($this->app, ComplianceDocumentRepository::class)->listForVehicle($golf->id);
        self::assertCount(1, $documents);
        self::assertSame('54.850', $documents[0]->data->cost, 'the owner\'s own, not a second from DVSA');
    }

    public function testDefectsBecomeIssuesWithTheDecidedStatuses(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        [, $fail, $pass] = $this->tests($golf);

        $browser->post($this->url($golf), ['do' => 'issues', 'test' => (string) $fail->id]);
        $browser->post($this->url($golf), ['do' => 'issue', 'defect' => (string) $pass->defects[0]->id]);

        $issues = $this->issues($golf);
        self::assertCount(9, $issues);
        $byTitle = [];
        foreach ($issues as $issue) {
            $byTitle[$issue->data->title] = $issue;
            self::assertSame(IssueSource::MotAdvisory, $issue->source);
        }
        $dangerous = $byTitle['Nearside Front Tyre tread depth below requirements of 1.6mm (5.2.3 (e))'];
        self::assertSame(IssueStatus::Open, $dangerous->status());
        self::assertTrue($dangerous->data->affectsSafety);
        self::assertSame('223456789012', $dangerous->sourceRef);
        self::assertSame('2026-02-14', $dangerous->data->noticedOn->format('Y-m-d'));
        self::assertSame('70730.669', $dangerous->data->odometerKm);
        self::assertTrue($byTitle['Offside Rear Brake pipe excessively corroded (1.1.11 (c))']->data->affectsSafety, 'major');
        self::assertSame(IssueStatus::Open, $byTitle['Headlamp aim too high']->status());
        self::assertFalse($byTitle['Headlamp aim too high']->data->affectsSafety, 'a fail alone is not a safety flag');
        foreach (['Customer advised of wiper wear', 'Test station note', 'Corrosion noted', 'No type given'] as $watched) {
            self::assertSame(IssueStatus::Watching, $byTitle[$watched]->status(), $watched);
            // Even from the fail: before the next MOT (#337).
            self::assertSame('2027-02-11', $byTitle[$watched]->data->lookAgainOn?->format('Y-m-d'));
        }

        // An advisory on an older pass: watching, looked at again 30 days before the latest
        // test's expiry, so before the next MOT (#337).
        $advisory = $byTitle['Nearside Front Tyre worn close to legal limit/worn on edge (5.2.3 (e))'];
        self::assertSame(IssueStatus::Watching, $advisory->status());
        self::assertSame('2027-02-11', $advisory->data->lookAgainOn?->format('Y-m-d'));
        self::assertFalse($advisory->data->affectsSafety);

        // No second reading: the issue's odometer is the MOT's (#319).
        $readings = $this->service($this->app, OdometerReadingRepository::class)->listForVehicle($golf->id);
        self::assertSame([], array_filter($readings, static fn ($r): bool => $r->source === OdometerSource::Issue));
    }

    public function testALongDefectKeepsItsFullTextInTheDescription(): void
    {
        $this->start();
        $long = str_repeat('Corrosion to the sub-frame mounting ', 5);
        $this->answer = fn (string $url): MockResponse => $this->withDefect($long, 'ADVISORY');
        $golf = $this->golf();
        $browser = $this->fetch($golf);

        $defect = $this->defectWithText($golf, trim($long));
        $browser->post($this->url($golf), ['do' => 'issue', 'defect' => (string) $defect->id]);

        $issue = $this->issues($golf)[0];
        self::assertSame(120, mb_strlen($issue->data->title));
        self::assertSame(trim($long), $issue->data->description);
    }

    public function testNotNowSticksAndDoneClearsTheCard(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        [, , $pass] = $this->tests($golf);

        $browser->post($this->url($golf), ['do' => 'not_now', 'defect' => (string) $pass->defects[1]->id]);
        $this->fetch($golf, $browser);
        $stored = $this->tests($golf)[2]->defects[1];
        self::assertNotNull($stored->dismissedAt, 'a refresh keeps Not now');

        foreach ($this->tests($golf) as $test) {
            $browser->post($this->url($golf), ['do' => 'done', 'test' => (string) $test->id]);
        }
        $response = $browser->get('/vehicles/' . $golf->id . '/mot-history');
        self::assertStringNotContainsString('data-review-link', (string) $response->getBody());
        self::assertSame([], $this->issues($golf), 'Done adds nothing');
    }

    public function testNoLookAgainPointOnceTheLatestMotIsDue(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        // 12 Feb 2027: past 30 days before the latest expiry (13 Mar 2027).
        $this->clock->set(new DateTimeImmutable('2027-02-12T09:00:00Z'));
        // Months on, a fresh sign-in.
        $browser = $this->browserFor($this->app, 'owner');

        $browser->post($this->url($golf), ['do' => 'issue', 'defect' => (string) $this->tests($golf)[2]->defects[0]->id]);

        $issue = $this->issues($golf)[0];
        self::assertSame(IssueStatus::Watching, $issue->status());
        self::assertNull($issue->data->lookAgainOn, 'never a point already passed');
    }

    public function testAnAdviceRepeatedAfterARetestStillFindsItsIssue(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $browser->post($this->url($golf), ['do' => 'issue', 'defect' => (string) $this->tests($golf)[2]->defects[0]->id]);

        // A year on, after the 2026 fail and its retest (no defects), the tyre is advised again (#338).
        $this->answer = fn (): MockResponse => $this->withNextTest(
            'Nearside Front Tyre worn close to legal limit/worn on edge (5.2.3 (e))',
        );
        $this->fetch($golf, $browser);

        $issues = $this->issues($golf);
        self::assertCount(1, $issues);
        $latest = $this->tests($golf)[0];
        self::assertSame('2027-03-01', $latest->completedAt->format('Y-m-d'));
        self::assertSame($issues[0]->id, $latest->defects[0]->issueId);
    }

    public function testTheCardIsReadOnlyWhileIssuesAreOff(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $toggles = $this->service($this->app, FeatureToggles::class);
        $toggles->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Issues)));
        $browser->get('/vehicles/' . $golf->id . '/mot-history');
        $browser->get($this->url($golf));

        $toggles->save(Feature::cases());

        self::assertStringContainsString('Add as issue', (string) $browser->get($this->url($golf))->getBody());
        foreach ($this->tests($golf) as $test) {
            self::assertNull($test->reviewedAt, 'viewing reviews nothing');
        }
    }

    public function testAnAdvisoryAdvisedAgainUpdatesItsIssue(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $advisory = $this->tests($golf)[2]->defects[0];
        $browser->post($this->url($golf), ['do' => 'issue', 'defect' => (string) $advisory->id]);

        // The next MOT advises it again (case and spacing aside).
        $this->answer = fn (string $url): MockResponse => $this->withDefect(
            'nearside front tyre  worn close to legal limit/worn on edge (5.2.3 (e))',
            'ADVISORY',
        );
        $this->fetch($golf, $browser);

        $issues = $this->issues($golf);
        self::assertCount(1, $issues, 'not offered as a new issue');
        $repeat = $this->defectWithText($golf, 'nearside front tyre worn close to legal limit/worn on edge (5.2.3 (e))');
        self::assertSame($issues[0]->id, $repeat->issueId);
        $notes = $this->connection($this->app)->fetchFirstColumn(
            'SELECT note FROM issue_updates WHERE issue_id = ?',
            [$issues[0]->id],
        );
        self::assertContains('Advised again at the MOT on 14 Feb 2026, 43,950 mi', $notes);
        $page = (string) $browser->get($this->url($golf))->getBody();
        self::assertStringContainsString('Advised again</span>', $page, 'a short pill');
        self::assertMatchesRegularExpression(
            '#Added to the issue: <a href="[^"]*/issues/' . $issues[0]->id . '">Nearside Front Tyre#',
            $page,
            'the title wraps as a link, outside the pill',
        );
    }

    public function testAnIssueNotAdvisedAgainIsNotedButNotClosed(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $advisory = $this->tests($golf)[2]->defects[0];
        $browser->post($this->url($golf), ['do' => 'issue', 'defect' => (string) $advisory->id]);

        $page = (string) $browser->get($this->url($golf))->getBody();

        self::assertStringContainsString('not advised at the following MOT', $page);
        self::assertSame(IssueStatus::Watching, $this->issues($golf)[0]->status());
    }

    public function testANewVehicleIsOfferedItsFirstMotDueDateAndNeverGivenItAlone(): void
    {
        $this->start();
        $this->answer = static fn (): MockResponse => new MockResponse(
            (string) file_get_contents(self::FIXTURES . 'new-vehicle.json'),
        );
        $puma = $this->golf('XY25 ABC', make: 'Ford', model: 'Puma');
        $browser = $this->fetch($puma);

        self::assertNull($this->vehicle($puma)->data->firstInspectionDueOn, 'never filled on its own');
        $page = (string) $browser->get($this->url($puma))->getBody();
        self::assertStringContainsString('DVSA: first MOT due 13 Mar 2028.', $page);

        $browser->post($this->url($puma), ['do' => 'first_due']);

        self::assertSame('2028-03-13', $this->vehicle($puma)->data->firstInspectionDueOn?->format('Y-m-d'));
        self::assertStringNotContainsString('data-first-due', (string) $browser->get($this->url($puma))->getBody());
    }

    public function testAnyoneWhoCanLogReviewsButOnlyTheOwnerFetches(): void
    {
        $this->start();
        $golf = $this->golf();
        $this->fetch($golf);
        $logger = $this->shareWith($golf, ShareLevel::Log);
        $viewer = $this->shareWith($golf, ShareLevel::View, 'viewer');

        self::assertSame(200, $logger->get($this->url($golf))->getStatusCode());
        $logger->post($this->url($golf), ['do' => 'issue', 'defect' => (string) $this->tests($golf)[2]->defects[0]->id]);
        self::assertCount(1, $this->issues($golf));
        // First MOT due is the vehicle form's: Manage.
        $logger->post($this->url($golf), ['do' => 'first_due']);

        self::assertContains($viewer->get($this->url($golf))->getStatusCode(), [403, 404]);
        self::assertSame(200, $viewer->get('/vehicles/' . $golf->id . '/mot-history')->getStatusCode());
    }

    public function testTheOverviewNoticesANewResultUntilItIsReviewed(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $viewer = $this->shareWith($golf, ShareLevel::View);

        $overview = (string) $browser->get('/vehicles/' . $golf->id)->getBody();
        self::assertStringContainsString('data-mot-result', $overview);
        self::assertStringContainsString('New MOT result: passed 15 Feb 2026.', $overview);
        self::assertStringContainsString($this->url($golf), $overview);
        self::assertStringNotContainsString('data-mot-result', (string) $viewer->get('/vehicles/' . $golf->id)->getBody());

        // Only the newest test counts: once it is done, the notice goes, whatever is left on older ones.
        $browser->post($this->url($golf), ['do' => 'done', 'test' => (string) $this->tests($golf)[0]->id]);
        self::assertStringNotContainsString('data-mot-result', (string) $browser->get('/vehicles/' . $golf->id)->getBody());
    }

    public function testAPassAddedFromTheCardClosesTheReplacedMotReminderAsDone(): void
    {
        $this->start();
        $golf = $this->golf();
        $old = $this->service($this->app, ComplianceService::class)->create($golf, new ComplianceDocumentData(
            ComplianceType::Inspection,
            startOn: new DateTimeImmutable('2025-03-01'),
            expiryOn: new DateTimeImmutable('2026-02-28'),
        ), new DateTimeZone('Europe/London'));
        $sync = $this->service($this->app, ReminderSync::class);
        $sync->sync($this->owner);
        $reminder = $this->reminderFor($old->id);
        self::assertNotNull($reminder);
        self::assertTrue($reminder->status->isOpen());

        $browser = $this->fetch($golf);
        $browser->post($this->url($golf), ['do' => 'documents']);
        $sync->sync($this->owner);

        $closed = $this->reminderFor($old->id);
        self::assertNotNull($closed, 'kept, not deleted as a replaced document\'s is');
        self::assertSame(ReminderStatus::Done, $closed->status);
    }

    public function testAManualRenewalStillDropsTheReplacedReminder(): void
    {
        $this->start();
        $golf = $this->golf();
        $compliance = $this->service($this->app, ComplianceService::class);
        $zone = new DateTimeZone('Europe/London');
        $old = $compliance->create($golf, new ComplianceDocumentData(
            ComplianceType::Inspection,
            startOn: new DateTimeImmutable('2025-03-01'),
            expiryOn: new DateTimeImmutable('2026-02-28'),
        ), $zone);
        $sync = $this->service($this->app, ReminderSync::class);
        $sync->sync($this->owner);
        $compliance->create($golf, new ComplianceDocumentData(
            ComplianceType::Inspection,
            provider: 'Kwik MOT',
            startOn: new DateTimeImmutable('2026-02-20'),
            expiryOn: new DateTimeImmutable('2027-02-19'),
        ), $zone);
        $sync->sync($this->owner);

        self::assertNull($this->reminderFor($old->id));
    }

    private function reminderFor(int $documentId): ?Reminder
    {
        $vehicleIds = array_map(static fn (Vehicle $v): int => $v->id, $this->ownedVehicles($this->app, $this->owner->id));
        foreach ($this->service($this->app, ReminderRepository::class)->listGeneratedForVehicles($vehicleIds) as $reminder) {
            if ($reminder->source === ReminderSource::Compliance && $reminder->sourceId === $documentId) {
                return $reminder;
            }
        }

        return null;
    }

    public function testANumberlessPassLateAtNightIsLoggedOnceOnTheOwnersDay(): void
    {
        $this->start();
        // A Northern Ireland pass with no number, at 00:30 BST on 1 July (23:30 UTC on 30 June).
        $json = (string) file_get_contents(self::FIXTURES . 'vehicle-with-tests.json');
        $this->answer = static fn (): MockResponse => new MockResponse(
            str_replace('2019-03-01T08:00:00.000Z', '2019-06-30T23:30:00.000Z', $json),
        );
        $golf = $this->golf();
        $browser = $this->fetch($golf);

        $browser->post($this->url($golf), ['do' => 'documents']);
        $browser->post($this->url($golf), ['do' => 'documents']);
        $numberless = array_values(array_filter(
            $this->tests($golf),
            static fn (MotTest $test): bool => $test->reference() === null,
        ))[0];
        $documents = array_values(array_filter(
            $this->service($this->app, ComplianceDocumentRepository::class)->listForVehicle($golf->id),
            static fn ($document): bool => $document->data->reference === null,
        ));

        self::assertCount(1, $documents, 'never added twice');
        self::assertSame('2019-07-01', $documents[0]->data->startOn?->format('Y-m-d'), 'the owner\'s day');
        self::assertNotNull($numberless->reviewedAt, 'seen as logged, so the card lets it go');
    }

    public function testIssuesMadeBeforeStopAndRemoveAreNotOfferedAgain(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $browser->post($this->url($golf), ['do' => 'issues']);
        $made = count($this->issues($golf));
        self::assertGreaterThan(0, $made);

        $browser->post('/vehicles/' . $golf->id . '/mot-history/stop', []);
        $this->fetch($golf, $browser);
        $card = (string) $browser->get($this->url($golf))->getBody();
        $browser->post($this->url($golf), ['do' => 'issues']);

        self::assertCount($made, $this->issues($golf), 'linked again, not duplicated');
        self::assertStringNotContainsString('Add all as issues', $card);
        foreach ($this->tests($golf) as $test) {
            foreach ($test->defects as $defect) {
                self::assertTrue($defect->settled(), $defect->text . ' is linked again or put off');
            }
        }
    }

    public function testReorderedDefectsKeepTheirOwnIssueAndNotNow(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $fail = $this->tests($golf)[1];
        $headlamp = $this->defectWithText($golf, 'Headlamp aim too high');
        $wiper = $this->defectWithText($golf, 'Customer advised of wiper wear');
        $browser->post($this->url($golf), ['do' => 'issue', 'defect' => (string) $headlamp->id]);
        $browser->post($this->url($golf), ['do' => 'not_now', 'defect' => (string) $wiper->id]);
        $issueId = $this->issues($golf)[0]->id;

        // DVSA answers again with the same defects in the opposite order.
        $this->answer = fn (): MockResponse => $this->withFailDefectsReversed();
        $this->fetch($golf, $browser);

        $after = $this->defectWithText($golf, 'Headlamp aim too high');
        self::assertSame($fail->id, $after->motTestId);
        self::assertSame($issueId, $after->issueId, 'the issue stays with its own text');
        self::assertNull($after->dismissedAt);
        $wiperAfter = $this->defectWithText($golf, 'Customer advised of wiper wear');
        self::assertNull($wiperAfter->issueId);
        self::assertNotNull($wiperAfter->dismissedAt, 'Not now stays with its own text');
        $defects = $this->tests($golf)[1]->defects;
        foreach ($defects as $defect) {
            if (!in_array($defect->text, ['Headlamp aim too high', 'Customer advised of wiper wear'], true)) {
                self::assertNull($defect->issueId, $defect->text . ' got nothing it was not given');
                self::assertNull($defect->dismissedAt, $defect->text . ' was not put off');
            }
        }
        self::assertSame(range(0, count($defects) - 1), array_map(
            static fn (MotDefect $defect): int => $defect->position,
            $defects,
        ), 'positions follow DVSA\'s order');
    }

    public function testAnOlderTestNeverReviewedIsNotAdvisedAgainAfterTheIssueItBecame(): void
    {
        $this->start();
        $oil = 'Oil leak, but not excessive (8.4.1 (a) (i))';
        $this->answer = fn (): MockResponse => $this->withNextTest($oil);
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        // The 2025 pass (never reviewed) has the same text as the 2027 advisory the owner makes an issue of.
        $latest = $this->tests($golf)[0];
        $browser->post($this->url($golf), ['do' => 'issue', 'defect' => (string) $latest->defects[0]->id]);

        $this->fetch($golf, $browser);

        $issue = $this->issues($golf)[0];
        $notes = $this->connection($this->app)->fetchFirstColumn(
            'SELECT note FROM issue_updates WHERE issue_id = ?',
            [$issue->id],
        );
        self::assertSame([], $notes, 'no "advised again" for a test before the one the issue came from');
        $older = array_values(array_filter(
            $this->tests($golf),
            static fn (MotTest $test): bool => $test->completedAt->format('Y') === '2025',
        ))[0];
        self::assertNull($older->defects[2]->issueId, 'the older defect is not tied to the later issue');
    }

    private function url(Vehicle $vehicle): string
    {
        return '/vehicles/' . $vehicle->id . '/mot-history/review';
    }

    /**
     * @return list<MotTest> newest first
     */
    private function tests(Vehicle $vehicle): array
    {
        return $this->service($this->app, MotTestRepository::class)->listForVehicle($vehicle->id);
    }

    /**
     * @return list<Issue>
     */
    private function issues(Vehicle $vehicle): array
    {
        return $this->service($this->app, IssueRepository::class)->listForVehicle($vehicle->id);
    }

    private function vehicle(Vehicle $vehicle): Vehicle
    {
        $fresh = $this->service($this->app, VehicleRepository::class)->findById($vehicle->id);
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function defectWithText(Vehicle $vehicle, string $text): MotDefect
    {
        foreach ($this->tests($vehicle) as $test) {
            foreach ($test->defects as $defect) {
                if ($defect->text === $text) {
                    return $defect;
                }
            }
        }
        self::fail('No defect ' . $text);
    }

    /**
     * The fixture with a 2027 pass on top, advising $text.
     */
    private function withNextTest(string $text): MockResponse
    {
        $json = (string) file_get_contents(self::FIXTURES . 'vehicle-with-tests.json');
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $tests = $data['motTests'] ?? null;
        self::assertIsArray($tests);
        array_unshift($tests, [
            'completedDate' => '2027-03-01T10:00:00.000Z',
            'testResult' => 'PASSED',
            'expiryDate' => '2028-03-13',
            'odometerValue' => '47000',
            'odometerUnit' => 'MI',
            'odometerResultType' => 'READ',
            'motTestNumber' => '423456789099',
            'dataSource' => 'DVSA',
            'defects' => [['text' => $text, 'type' => 'ADVISORY', 'dangerous' => false]],
        ]);
        $data['motTests'] = $tests;

        return new MockResponse((string) json_encode($data));
    }

    /**
     * The fixture with the 2026 fail's defects in the opposite order.
     */
    private function withFailDefectsReversed(): MockResponse
    {
        $json = (string) file_get_contents(self::FIXTURES . 'vehicle-with-tests.json');
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $tests = $data['motTests'] ?? null;
        self::assertIsArray($tests);
        $fail = $tests[1] ?? null;
        self::assertIsArray($fail);
        $defects = $fail['defects'] ?? null;
        self::assertIsArray($defects);
        $fail['defects'] = array_reverse($defects);
        $tests[1] = $fail;
        $data['motTests'] = $tests;

        return new MockResponse((string) json_encode($data));
    }

    /**
     * The fixture with one more defect on the 2026 fail.
     */
    private function withDefect(string $text, string $type): MockResponse
    {
        $json = (string) file_get_contents(self::FIXTURES . 'vehicle-with-tests.json');
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $tests = $data['motTests'] ?? null;
        self::assertIsArray($tests);
        $fail = $tests[1] ?? null;
        self::assertIsArray($fail);
        $defects = $fail['defects'] ?? null;
        self::assertIsArray($defects);
        $defects[] = ['text' => $text, 'type' => $type, 'dangerous' => false];
        $fail['defects'] = $defects;
        $tests[1] = $fail;
        $data['motTests'] = $tests;

        return new MockResponse((string) json_encode($data));
    }
}
