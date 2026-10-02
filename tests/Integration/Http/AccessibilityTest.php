<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Domain\Incident\WriteOffCategory;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Kernel;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\PlaceData;
use Logbook\Domain\Station\StationData;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\PlaceRepository;
use Logbook\Repository\StationRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\TestBrowser;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\UploadedFile;

/**
 * Accessibility smoke checks over the core flows (spec.md §8, CLAUDE.md §7):
 * every page has a language, one main heading and a skip link; every form
 * control is labelled; every link, button and image has a text alternative;
 * ids are unique and ARIA references resolve; nothing forces a tab order.
 * Not a substitute for trying the pages with a keyboard and screen reader,
 * but it keeps regressions out.
 */
final class AccessibilityTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';

    /** The locale the pages under test are in. */
    private string $language = 'en_GB';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function languages(): iterable
    {
        yield 'English' => ['en_GB', 'Settings'];
        yield 'German' => ['de_DE', 'Einstellungen'];
    }

    #[DataProvider('languages')]
    public function testSignedInPages(string $locale, string $settingsWord): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);
        $this->service($app, UserRepository::class)->updateProfile(
            $owner->id,
            $owner->displayName,
            $owner->preferences->withLocale($locale),
            new DateTimeImmutable(self::NOW),
        );
        $this->language = $locale;
        self::assertStringContainsString('>' . $settingsWord . '<', self::body($browser->get('/settings')));
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $bike = $this->vehicle($app, 'Honda', 'CB500');
        $this->fillUp($app, $golf, '2026-08-01T08:00:00Z', '1000', '40', '60');
        $fill = $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '1600', '38', '57');
        $service = $this->maintenance($app, $golf, '2026-09-14', 'Annual service', '189.5', '1700');
        $this->service($app, ScheduleService::class)->create($golf, new MaintenanceScheduleData(
            category: MaintenanceCategory::Oil,
            title: 'Oil change',
            intervalMonths: 12,
            baselineDoneOn: LocalTime::parseDate('2025-10-01'),
        ));
        $document = $this->document($app, $golf, ComplianceType::Insurance, '2026-01-01', '2026-10-05', '420', 'Admiral');
        $expense = $this->expense($app, $golf, '2026-09-20', '0');
        $due = LocalTime::parseDate('2026-10-01');
        self::assertNotNull($due);
        $reminder = $this->service($app, ReminderService::class)
            ->createManual($this->owner($app), new ManualReminderData($golf->id, 'Wash', $due, 7));
        $id = $golf->id;
        // Incidents (Phase 27.1): one with a claim, its service record linked.
        $incident = $this->service($app, IncidentService::class)->create($golf, new IncidentData(
            LocalTime::parseDate('2026-09-10') ?? throw new \LogicException('date'),
            IncidentType::ParkedDamage,
            damageAreas: [DamageArea::Rear],
            claim: new Claim(ClaimStatus::Open, 'Admiral', claimNumber: '4417', payout: '100.000', repairEstimate: '120.000'),
        ), null, new DateTimeZone('Europe/London'));
        $this->service($app, IncidentService::class)->link($golf, LinkKind::Maintenance, $service->id, $incident);
        // Phase 27.2: a settled Cat S, so *Archive* opens the confirm page.
        $this->service($app, IncidentService::class)->create($golf, new IncidentData(
            LocalTime::parseDate('2026-09-12') ?? throw new \LogicException('date'),
            IncidentType::Collision,
            damageAreas: [DamageArea::Front],
            writeOff: WriteOffCategory::CatS,
            claim: new Claim(ClaimStatus::Settled, 'Admiral', claimNumber: '5521', payout: '9000.000'),
        ), null, new DateTimeZone('Europe/London'));

        // Stations (Phase 30.1): two spellings of one, the fill-ups linked, a place.
        $stations = $this->service($app, StationRepository::class);
        $when = new DateTimeImmutable(self::NOW);
        $data = new StationData('Tesco Antrim', 'Tesco', latitude: '54.7154', longitude: '-6.2164', grades: [FuelGrade::E10_95]);
        $tesco = $stations->insert($data, $owner->id, $when);
        $tescoDot = $stations->insert(new StationData('Tesco Antrim.', postcode: 'BT41 4LD'), $owner->id, $when);
        $stations->setFavourite($owner->id, $tesco, true, $when);
        $fuelEntries = $this->service($app, FuelEntryRepository::class);
        foreach ($fuelEntries->listForVehicle($golf->id) as $entry) {
            $fuelEntries->update($golf->id, $entry->id, $entry->data->withStation($tesco, 'Tesco Antrim'), $when);
        }
        $place = $this->service($app, PlaceRepository::class)
            ->insert($owner->id, new PlaceData('Home', '54.706400', '-6.216400'), $when);

        // Finance (Phase 29.1): a PCP with a missed payment, an extra payment and a quote.
        $finance = $this->service($app, FinanceAgreementRepository::class);
        $agreement = $finance->insert($golf->id, new AgreementData(
            type: AgreementType::Pcp,
            lender: 'Volkswagen Financial Services',
            agreementNumber: 'VWFS-12345678',
            startedOn: LocalTime::parseDate('2025-03-01') ?? throw new \LogicException('date'),
            firstPaymentOn: LocalTime::parseDate('2025-04-01') ?? throw new \LogicException('date'),
            numberOfPayments: 36,
            regularPayment: '250',
            finalPayment: '9000',
            cashPrice: '22000',
            customerDeposit: '2000',
            apr: '6.9',
            annualMileageAllowance: 8000,
            excessMileageCharge: '0.09',
        ), new DateTimeImmutable(self::NOW), $owner->id);
        $at = new DateTimeImmutable(self::NOW);
        $finance->insertEvent($agreement, PaymentEventKind::Missed, LocalTime::parseDate('2026-06-01'), null, null, null, $at);
        $finance->insertEvent($agreement, PaymentEventKind::Extra, null, '500', LocalTime::parseDate('2026-05-10'), null, $at);
        $finance->insertQuote(
            $agreement,
            LocalTime::parseDate('2026-09-01') ?? throw new \LogicException('date'),
            '16000',
            LocalTime::parseDate('2026-09-30') ?? throw new \LogicException('date'),
            null,
            $at,
        );

        $pages = [
            '/', '/?customise=1', '/garage', '/vehicles/new', "/vehicles/$id", "/vehicles/$id/edit", "/vehicles/$id/delete",
            "/vehicles/$id/history", "/vehicles/$id/history?kind=fuel", "/vehicles/$id/history/print", '/history',
            "/vehicles/$id/sale-pack", "/vehicles/$id/sale-pack?timeline=1&costs=1",
            '/upcoming', "/upcoming?vehicle=$id",
            "/vehicles/$id/odometer", "/vehicles/$id/odometer/new",
            '/fuel/new', "/vehicles/$id/fuel", "/vehicles/$id/fuel?trend=cost", "/vehicles/$id/fuel/new",
            "/vehicles/$id/fuel/{$fill->id}/edit",
            "/vehicles/$id/maintenance", "/vehicles/$id/maintenance/new", "/vehicles/$id/maintenance/{$service->id}/edit",
            "/vehicles/$id/maintenance/schedules/new",
            "/vehicles/$id/documents", "/vehicles/$id/documents/new", "/vehicles/$id/documents/{$document->id}/edit",
            "/vehicles/$id/expenses", "/vehicles/$id/expenses/new", "/vehicles/$id/expenses/{$expense->id}/edit",
            '/reminders', '/reminders/new', "/reminders/{$reminder->id}/edit",
            '/reports', '/reports?range=all', '/reports/ownership', '/reports/ownership?include_archived=1',
            '/settings', '/settings/reminders', '/settings/modules', '/settings/backup',
            "/vehicles/$id/import/fuel",
            "/vehicles/$id/incidents", "/vehicles/$id/incidents/new", "/vehicles/$id/incidents/{$incident->id}",
            "/vehicles/$id/incidents/{$incident->id}/edit", '/incidents/history', "/vehicles/$id/history?kind=incidents",
            "/vehicles/$id/sale-pack?options=1&incidents=1&kinds[]=incident_photos",
            "/vehicles/$id/archive",
            // Phase 28.1: Settings → Jobs (the dashboard above carries the scheduler notice).
            '/settings/jobs',
            // Phase 28.2: Settings → Updates.
            '/settings/updates',
            // Phase 29.1: finance agreements.
            "/vehicles/$id/finance", "/vehicles/$id/finance/$agreement", "/vehicles/$id/finance/$agreement/edit",
            "/vehicles/{$bike->id}/finance/new?type=lease", "/vehicles/{$bike->id}/finance/new?type=hp",
            // Phase 29.2: ending an agreement (the archive page above has its finance choices too).
            "/vehicles/$id/finance/$agreement/end",
            // Phase 30.1: stations, merging, duplicates and places.
            '/stations', '/stations?q=tesco', "/stations/$tesco", "/stations/$tesco/edit", '/stations/new',
            "/stations/$tesco/merge", "/stations/$tesco/merge?with=$tescoDot", '/stations/duplicates',
            '/settings/places', '/settings/places/new', "/settings/places/$place/edit",
        ];
        foreach ($pages as $page) {
            $response = $browser->get($page);
            self::assertSame(200, $response->getStatusCode(), $page);
            $this->assertAccessible(self::body($response), $page);
        }

        // Phase 28.1: a job run's page, after Run now.
        $run = $browser->post('/settings/jobs/cleanup/run')->getHeaderLine('Location');
        self::assertStringStartsWith('/settings/jobs/runs/', $run);
        $this->assertAccessible(self::body($browser->get($run)), 'job run');

        // The import's mapping and preview pages.
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-a11y-');
        file_put_contents($path, "Date,Amount\n2026-09-01,4\nnot a date,5\n");
        $upload = new UploadedFile($path, 'costs.csv', 'text/csv', (int) filesize($path), UPLOAD_ERR_OK);
        $map = $browser->post("/vehicles/$id/import/expenses", [], ['file' => $upload])->getHeaderLine('Location');
        $mapping = self::body($browser->get($map));
        $this->assertAccessible($mapping, 'import mapping');
        $query = Html::formValues(Html::element(Html::document($mapping), 'form[method="get"]'));
        $this->assertAccessible(self::body($browser->get($map . '?' . http_build_query($query))), 'import preview');
        @unlink($path);
    }

    public function testSignedOutPages(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $visitor = new TestBrowser($app);
        $this->assertAccessible(self::body($visitor->get('/setup')), '/setup');

        $this->createOwner($app);
        $this->assertAccessible(self::body($visitor->get('/login')), '/login');
        $this->assertAccessible(self::body($visitor->get('/offline')), '/offline');
        $this->assertAccessible(self::body($visitor->get('/no/such/page')), 'not found');
    }

    public function testTheChecksCatchProblems(): void
    {
        $bad = '<!doctype html><html><body><main id="main"><h1>A</h1><h1>B</h1>'
            . '<input name="odometer"><a href="/x"><svg></svg></a><img src="x.png">'
            . '<span id="dup"></span><span id="dup"></span><p aria-describedby="nowhere"></p></main></body></html>';

        try {
            $this->assertAccessible($bad, 'bad');
        } catch (AssertionFailedError $e) {
            $expected = [
                'no lang',
                '2 <h1>',
                'no skip link',
                'unlabelled <input name="odometer">',
                '<a> without a name',
                'image without alt',
                'duplicate id dup',
                'points nowhere',
            ];
            foreach ($expected as $problem) {
                self::assertStringContainsString($problem, $e->getMessage());
            }

            return;
        }
        self::fail('the checks passed a page full of problems');
    }

    private function assertAccessible(string $html, string $page): void
    {
        $document = Html::document($html);
        $problems = [];

        $lang = $document->documentElement?->getAttribute('lang') ?? '';
        if ($lang === '') {
            $problems[] = 'no lang on <html>';
        } elseif (!str_starts_with($lang, explode('_', $this->language)[0])) {
            $problems[] = sprintf('lang="%s" in a %s page', $lang, $this->language);
        }

        // A translation key on the page means a string is missing from the catalogue.
        $text = '';
        foreach ($document->querySelectorAll('body *:not(script):not(pre):not(code)') as $element) {
            foreach ($element->childNodes as $node) {
                $text .= $node instanceof Text ? ' ' . $node->textContent : '';
            }
        }
        if (preg_match_all('/\b(?:' . implode('|', self::sections()) . ')\.[a-z0-9_]+(?:\.[a-z0-9_]+)*\b/', $text, $keys) > 0) {
            $problems[] = 'untranslated: ' . implode(', ', array_unique($keys[0]));
        }
        $h1 = $document->querySelectorAll('h1')->length;
        if ($h1 !== 1) {
            $problems[] = sprintf('%d <h1> elements', $h1);
        }
        if ($document->querySelector('a.skip-link[href="#main"]') === null || $document->getElementById('main') === null) {
            $problems[] = 'no skip link to #main';
        }

        $ids = [];
        foreach ($document->querySelectorAll('[id]') as $element) {
            $elementId = (string) $element->getAttribute('id');
            if (isset($ids[$elementId])) {
                $problems[] = 'duplicate id ' . $elementId;
            }
            $ids[$elementId] = true;
        }
        foreach ($document->querySelectorAll('[aria-describedby], [aria-labelledby], label[for]') as $element) {
            foreach (['aria-describedby', 'aria-labelledby', 'for'] as $attribute) {
                foreach (preg_split('/\s+/', trim((string) $element->getAttribute($attribute))) ?: [] as $ref) {
                    if ($ref !== '' && !isset($ids[$ref])) {
                        $problems[] = sprintf('%s="%s" points nowhere', $attribute, $ref);
                    }
                }
            }
        }

        foreach ($document->querySelectorAll('input, select, textarea') as $control) {
            $type = strtolower((string) $control->getAttribute('type'));
            if ($type === 'hidden' || $control->hasAttribute('hidden') || in_array($type, ['submit', 'button'], true)) {
                continue;
            }
            if (!$this->isLabelled($document, $control)) {
                $problems[] = sprintf('unlabelled <%s name="%s">', $control->localName, $control->getAttribute('name'));
            }
        }

        foreach ($document->querySelectorAll('a[href], button') as $control) {
            if ($this->accessibleText($control) === '' && !$control->hasAttribute('aria-label')) {
                $problems[] = sprintf('<%s> without a name: %s', $control->localName, $document->saveHtml($control));
            }
        }
        foreach ($document->querySelectorAll('img') as $image) {
            if (!$image->hasAttribute('alt')) {
                $problems[] = 'image without alt: ' . $image->getAttribute('src');
            }
        }
        foreach ($document->querySelectorAll('[tabindex]') as $element) {
            if ((int) $element->getAttribute('tabindex') > 0) {
                $problems[] = 'positive tabindex on <' . $element->localName . '>';
            }
        }
        foreach ($document->querySelectorAll('table') as $table) {
            if ($table->querySelector('th') === null) {
                $problems[] = 'table without headers';
            }
        }

        $problems = array_values(array_unique($problems));
        self::assertSame([], $problems, $page . ":\n" . implode("\n", $problems));
    }

    /**
     * The catalogue's top-level sections ("nav", "fuel", …).
     *
     * @return list<string>
     */
    private static function sections(): array
    {
        /** @var array<string, mixed> $catalogue */
        $catalogue = require Kernel::rootDir() . '/translations/messages+intl-icu.en.php';

        return array_keys($catalogue);
    }

    private function isLabelled(HTMLDocument $document, Element $control): bool
    {
        foreach (['aria-label', 'aria-labelledby', 'title'] as $attribute) {
            if ($control->hasAttribute($attribute)) {
                return true;
            }
        }
        $id = (string) $control->getAttribute('id');
        if ($id !== '') {
            foreach ($document->querySelectorAll('label[for]') as $label) {
                if ($label->getAttribute('for') === $id && $this->accessibleText($label) !== '') {
                    return true;
                }
            }
        }

        return $control->closest('label') !== null;
    }

    private function accessibleText(Element $element): string
    {
        $text = trim((string) $element->textContent);
        if ($text !== '') {
            return $text;
        }
        foreach ($element->querySelectorAll('[aria-label], img[alt]') as $child) {
            $label = trim((string) ($child->getAttribute('aria-label') ?? $child->getAttribute('alt')));
            if ($label !== '') {
                return $label;
            }
        }

        return trim((string) ($element->getAttribute('title') ?? ''));
    }
}
