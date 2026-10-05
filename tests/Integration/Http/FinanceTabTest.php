<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Dom\HTMLDocument;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * One vehicle header and the Finance tab (Phase 33.3, spec.md §7.2, §7.32
 * *Finance tab*, §8 *Vehicle header*): the name in one style on every tab
 * with exactly one <h1> per page, the tabs in the prototype's order, the
 * Finance tab for Manage with costs only, the agreement in the prototype's
 * cards (*Paid so far*, Purchase, Value & equity but not for a lease), the
 * empty card, earlier agreements, and every finance page within the tab.
 */
final class FinanceTabTest extends AppTestCase
{
    use CostFixtures;

    /** The PCP's 18th payment is due on 30 Jun 2026. */
    private const string NOW = '2026-06-30T10:00:00Z';

    /** Every tab, in the prototype's order (#179). */
    private const array TABS = [
        '', '/history', '/odometer', '/trips', '/fuel', '/maintenance', '/tyres', '/documents', '/incidents',
        '/finance', '/expenses',
    ];

    /**
     * FinanceTest's PCP: 3,000 of deposits and 18 × 250 paid by NOW.
     *
     * @return array<string, string>
     */
    private static function pcp(): array
    {
        return [
            'type' => 'pcp',
            'lender' => 'Toyota Financial Services',
            'agreement_number' => 'PCP-0012345678',
            'started_on' => '2024-12-31',
            'first_payment_on' => '2025-01-31',
            'number_of_payments' => '36',
            'regular_payment' => '250',
            'final_payment' => '8000',
            'cash_price' => '20000',
            'customer_deposit' => '2000',
            'dealer_contribution' => '1000',
            'apr' => '0',
            'count_in_costs' => '1',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function lease(): array
    {
        return [
            'type' => 'lease',
            'lender' => 'LeasePlan',
            'started_on' => '2025-03-10',
            'first_payment_on' => '2025-04-10',
            'number_of_payments' => '35',
            'regular_payment' => '300',
            'initial_rental' => '1800',
            'count_in_costs' => '1',
        ];
    }

    /**
     * @param array<string, string> $form
     * @param App<ContainerInterface> $app
     */
    private function add(App $app, TestBrowser $browser, Vehicle $vehicle, array $form): FinanceAgreement
    {
        $response = $browser->post('/vehicles/' . $vehicle->id . '/finance/new', $form);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
        $agreements = $this->service($app, FinanceAgreementRepository::class)->listForVehicle($vehicle->id);

        return $agreements[0];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function allModulesOn(App $app): void
    {
        $this->service($app, FeatureToggles::class)->save(Feature::cases());
    }

    /**
     * The tab bar's links, in order, relative to the vehicle.
     *
     * @return list<string>
     */
    private static function tabs(HTMLDocument $document, Vehicle $vehicle): array
    {
        $tabs = [];
        foreach ($document->querySelectorAll('nav.tabs a.tabs__link') as $link) {
            $tabs[] = substr((string) $link->getAttribute('href'), strlen('/vehicles/' . $vehicle->id));
        }

        return $tabs;
    }

    public function testEveryTabHasTheSameNameAndOneH1InThePrototypesOrder(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->allModulesOn($app);
        $yaris = $this->vehicle($app, 'Toyota', 'Yaris');

        foreach (self::TABS as $tab) {
            $url = '/vehicles/' . $yaris->id . $tab;
            $response = $browser->get($url);
            self::assertSame(200, $response->getStatusCode(), $url);
            $document = Html::document(self::body($response));

            self::assertSame(1, $document->querySelectorAll('h1')->length, $url . ': exactly one <h1>');
            $names = $document->querySelectorAll('.vehicle-hero__name');
            self::assertSame(1, $names->length, $url);
            $name = $names->item(0);
            self::assertNotNull($name);
            self::assertSame('vehicle-hero__name', $name->getAttribute('class'), $url . ': one style on every tab');
            self::assertSame($tab === '' ? 'H1' : 'P', $name->tagName, $url . ': the <h1> on Overview only');
            self::assertSame(self::TABS, self::tabs($document, $yaris), $url);
            $current = $document->querySelector('nav.tabs [aria-current="page"]');
            self::assertSame('/vehicles/' . $yaris->id . $tab, $current?->getAttribute('href'), $url);
            if ($tab !== '') {
                $title = $document->querySelector('h1')?->getAttribute('class');
                self::assertSame('visually-hidden', $title, $url . ': the tab title, hidden');
            }
        }

        $overview = Html::document(self::body($browser->get('/vehicles/' . $yaris->id)));
        $icons = [];
        foreach ($overview->querySelectorAll('nav.tabs a.tabs__link use') as $use) {
            $icons[] = substr((string) $use->getAttribute('href'), (int) strpos((string) $use->getAttribute('href'), '#') + 1);
        }
        self::assertSame('dashboard', $icons[0]);
        self::assertSame('description', $icons[7]);
        self::assertSame('account_balance', $icons[9]);
    }

    public function testTheFinanceTabIsForManageWithCostsAndTheHeaderButtonIsGone(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $shares = $this->service($app, VehicleShareRepository::class);
        $now = new DateTimeImmutable(self::NOW);
        $levels = [
            'manager' => [ShareLevel::Manage, true],
            'viewer' => [ShareLevel::View, true],
            'logger' => [ShareLevel::Log, true],
            'blindviewer' => [ShareLevel::View, false],
            'blindlogger' => [ShareLevel::Log, false],
        ];
        foreach ($levels as $username => [$level, $costs]) {
            $member = $this->createMember($app, $username);
            $shares->insert($golf->id, $member->id, $level, $costs, false, $now);
        }
        $tab = 'href="/vehicles/' . $golf->id . '/finance"';

        $overview = self::body($browser->get('/vehicles/' . $golf->id));
        self::assertStringContainsString($tab, $overview, 'the owner, before any agreement');
        self::assertStringNotContainsString('/finance/new', $overview, 'no Add finance in the header');
        $manager = $this->browserFor($app, 'manager');
        self::assertStringContainsString($tab, self::body($manager->get('/vehicles/' . $golf->id . '/fuel')));

        foreach (['viewer', 'logger', 'blindviewer', 'blindlogger'] as $username) {
            $them = $this->browserFor($app, $username);
            self::assertStringNotContainsString('/finance', self::body($them->get('/vehicles/' . $golf->id)), $username);
            self::assertSame(404, $them->get('/vehicles/' . $golf->id . '/finance')->getStatusCode(), $username);
        }

        $agreement = $this->add($app, $browser, $golf, self::pcp());
        self::assertStringNotContainsString(
            'btn" href="/vehicles/' . $golf->id . '/finance"',
            self::body($browser->get('/vehicles/' . $golf->id)),
            'no Finance button in the header',
        );

        $features = $this->service($app, FeatureToggles::class);
        $features->save(array_values(array_filter(
            Feature::cases(),
            static fn (Feature $feature): bool => $feature !== Feature::Finance && $features->isEnabled($feature),
        )));
        self::assertStringNotContainsString($tab, self::body($browser->get('/vehicles/' . $golf->id)), 'module off');
        self::assertSame(404, $browser->get('/vehicles/' . $golf->id . '/finance/' . $agreement->id)->getStatusCode());
    }

    public function testTheTabIsTheActiveAgreementInThePrototypesCards(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $created = $browser->post('/vehicles/new', [
            'type' => 'car',
            'make' => 'Toyota',
            'model' => 'Yaris',
            'fuel_type' => 'petrol',
            'currency' => '',
            'purchase_date' => '2024-12-31',
            'purchase_price' => '20000',
            'purchase_seller' => 'Halden Motors',
            'purchase_odometer' => '12000',
        ]);
        self::assertSame(303, $created->getStatusCode(), self::body($created));
        $yaris = $this->ownedVehicles($app, $this->owner($app)->id)[0];
        $agreement = $this->add($app, $browser, $yaris, self::pcp());

        $tab = self::body($browser->get('/vehicles/' . $yaris->id . '/finance'));
        $show = self::body($browser->get('/vehicles/' . $yaris->id . '/finance/' . $agreement->id));
        foreach (['tab' => $tab, 'agreement page' => $show] as $page => $html) {
            self::assertSame(1, Html::document($html)->querySelectorAll('h1')->length, $page);
            // The agreement card.
            self::assertStringContainsString('Toyota Financial Services · <span class="tabular">•••• 5678</span>', $html, $page);
            self::assertStringContainsString('Payment 18 of 36', $html, $page);
            self::assertStringContainsString('Ends Jan 2028', $html, $page);
            // 3,000 of deposits + 18 × 250.
            self::assertMatchesRegularExpression('/Paid so far<\/dt>\s*<dd[^>]*>£7,500\.00</', $html, $page);
            self::assertMatchesRegularExpression('/Still to pay<\/dt>\s*<dd[^>]*>£4,500\.00</', $html, $page);
            self::assertStringContainsString('plus the optional final payment of £8,000.00', $html, $page);
            self::assertStringContainsString('hand it back, or part-exchange it', $html, $page . ': the PCP end note');
            // Purchase, and Value & equity.
            self::assertStringContainsString('Halden Motors', $html, $page);
            self::assertStringContainsString('12,000 mi', $html, $page);
            self::assertStringContainsString('How it was paid', $html, $page);
            self::assertStringContainsString('Value &amp; equity', $html, $page);
            self::assertStringContainsString('Add a valuation to see your equity', $html, $page);
            // The agreement page's own sections.
            self::assertStringContainsString('finance-schedule', $html, $page);
            self::assertStringContainsString('/schedule.csv', $html, $page);
            self::assertStringContainsString('/end"', $html, $page);
        }
        self::assertStringNotContainsString('How did you buy it?', $tab);
        self::assertStringNotContainsString('finance-earlier', $tab, 'no earlier agreements yet');

        foreach (['/finance/' . $agreement->id . '/edit', '/finance/' . $agreement->id . '/end'] as $url) {
            $response = $browser->get('/vehicles/' . $yaris->id . $url);
            self::assertSame(200, $response->getStatusCode(), $url);
            $document = Html::document(self::body($response));
            self::assertSame(1, $document->querySelectorAll('h1')->length, $url);
            self::assertSame(
                '/vehicles/' . $yaris->id . '/finance',
                $document->querySelector('nav.tabs [aria-current="page"]')?->getAttribute('href'),
                $url . ': within the Finance tab',
            );
        }
        $csv = $browser->get('/vehicles/' . $yaris->id . '/finance/' . $agreement->id . '/schedule.csv');
        self::assertSame(200, $csv->getStatusCode());
    }

    public function testTheAgreementsOwnActionsSitInItsCardAndDeleteStaysInTheTab(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $agreement = $this->add($app, $browser, $golf, self::pcp());
        $url = '/vehicles/' . $golf->id . '/finance';
        $delete = $url . '/' . $agreement->id . '/delete';
        $document = Html::document(self::body($browser->get($url)));

        // Edit, End agreement and Delete agreement in the agreement card, apart from the vehicle's Delete.
        $card = $document->querySelector('section[aria-labelledby="finance-agreement-heading"]');
        self::assertNotNull($card);
        $link = $card->querySelector('a[href="' . $delete . '"]');
        self::assertNotNull($link, 'Delete agreement in the card');
        self::assertTrue($link->hasAttribute('data-modal'), 'in the modal, like End agreement');
        self::assertSame('Delete agreement', trim((string) $link->textContent));
        self::assertNotNull($card->querySelector('a[href="' . $url . '/' . $agreement->id . '/end"][data-modal]'));
        $toolbar = $document->querySelector('.list-toolbar');
        self::assertNotNull($toolbar);
        self::assertNull($toolbar->querySelector('a[href="' . $delete . '"]'), 'not in the toolbar');
        // Print is there, but not the page's main action.
        $print = $toolbar->querySelector('[data-print]');
        self::assertSame('btn', $print?->getAttribute('class'));
        // Purchase and Value & equity beside the agreement card.
        $aside = $document->querySelector('.finance-layout > .finance-layout__aside');
        self::assertNotNull($aside);
        self::assertNotNull($aside->querySelector('#finance-purchase-heading'));
        self::assertNotNull($aside->querySelector('#finance-value-heading'));

        // The confirmation page within the Finance tab; the modal has the question alone.
        $page = Html::document(self::body($browser->get($delete)));
        self::assertSame(1, $page->querySelectorAll('h1')->length);
        self::assertSame('Delete this finance agreement?', trim((string) $page->querySelector('h1')?->textContent));
        self::assertSame($url, $page->querySelector('nav.tabs [aria-current="page"]')?->getAttribute('href'));
        $modal = self::body($browser->get($delete, ['X-Logbook-Modal' => '1']));
        self::assertStringContainsString('data-modal-fragment', $modal);
        self::assertStringNotContainsString('nav class="tabs"', $modal);
        self::assertStringContainsString('action="' . $delete . '"', $modal);

        $deleted = $browser->post($delete);
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame([], $this->service($app, FinanceAgreementRepository::class)->listForVehicle($golf->id));
    }

    public function testAnArchivedVehicleWithoutAgreementsIsNotAskedHowItWasBought(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        self::assertSame(303, $browser->post('/vehicles/' . $golf->id . '/archive')->getStatusCode());

        $page = self::body($browser->get('/vehicles/' . $golf->id . '/finance'));
        self::assertStringContainsString('No finance agreements', $page);
        self::assertStringContainsString('No finance agreement was recorded for this vehicle.', $page);
        self::assertStringNotContainsString('How did you buy it?', $page);
        self::assertStringNotContainsString('/finance/new', $page);
    }

    public function testTheEmptyCardAndEarlierAgreements(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $url = '/vehicles/' . $golf->id . '/finance';

        $empty = self::body($browser->get($url));
        self::assertStringContainsString('How did you buy it?', $empty);
        self::assertStringContainsString('href="' . $url . '/new"', $empty, 'Add finance');
        self::assertStringNotContainsString('finance-earlier', $empty);
        $form = Html::document(self::body($browser->get($url . '/new')));
        self::assertSame(1, $form->querySelectorAll('h1')->length);
        $current = $form->querySelector('nav.tabs [aria-current="page"]')?->getAttribute('href');
        self::assertSame($url, $current, 'the add page within the tab');

        $first = $this->add($app, $browser, $golf, self::pcp());
        $ended = $browser->post($url . '/' . $first->id . '/end', [
            'outcome' => 'settled',
            'ended_on' => '2026-06-30',
            'settlement' => '12400',
        ]);
        self::assertSame(303, $ended->getStatusCode());

        $earlierOnly = self::body($browser->get($url));
        self::assertStringContainsString('How did you buy it?', $earlierOnly, 'the empty card above');
        self::assertStringContainsString('finance-earlier', $earlierOnly);
        self::assertStringContainsString('href="' . $url . '/' . $first->id . '"', $earlierOnly);
        self::assertLessThan(strpos($earlierOnly, 'finance-earlier'), strpos($earlierOnly, 'How did you buy it?'));

        $earlierPage = self::body($browser->get($url . '/' . $first->id));
        self::assertStringContainsString('Settled early', $earlierPage, 'the same cards for an ended agreement');
        self::assertStringContainsString('Paid so far', $earlierPage);
        self::assertStringNotContainsString('Still to pay', $earlierPage, 'nothing owed once ended');

        $this->add($app, $browser, $golf, self::lease());
        $withActive = self::body($browser->get($url));
        self::assertStringNotContainsString('How did you buy it?', $withActive);
        self::assertStringContainsString('LeasePlan', $withActive);
        self::assertStringContainsString('finance-earlier', $withActive);
        $earlierLink = 'href="' . $url . '/' . $first->id . '"';
        self::assertStringContainsString($earlierLink, $withActive, 'the PCP under Earlier agreements');
        self::assertStringNotContainsString('Value &amp; equity', $withActive, 'no Value & equity card for a lease');
        self::assertStringNotContainsString('part-exchange it', $withActive, 'the end note is PCP only');
    }
}
