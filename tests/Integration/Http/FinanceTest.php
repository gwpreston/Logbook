<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Expense\CostSource;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Expense\CostLedger;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Finance agreements end to end (spec.md §7.32, Phase 29.1): a PCP and an HP
 * typed from their paperwork, the figures on the agreement page and the
 * overview card, quotes, marks, the cost lines counted once, the overlap
 * warning, access, the module switch and the CSV.
 */
final class FinanceTest extends AppTestCase
{
    use CostFixtures;

    /** The PCP's 18th payment is due on 30 Jun 2026. */
    private const string NOW = '2026-06-30T10:00:00Z';

    /**
     * The PCP of the unit tests (FinanceFixtures::pcp()), as its form.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function pcp(array $overrides = []): array
    {
        return $overrides + [
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
            'annual_mileage_allowance' => '8000',
            'mileage_unit' => 'mi',
            'excess_mileage_charge' => '0.09',
            'count_in_costs' => '1',
        ];
    }

    /**
     * The HP at 9.9% (FinanceFixtures::hp()).
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function hp(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'hp',
            'lender' => 'Black Horse',
            'started_on' => '2024-01-15',
            'first_payment_on' => '2024-02-15',
            'number_of_payments' => '48',
            'regular_payment' => '301.35',
            'cash_price' => '15000',
            'customer_deposit' => '3000',
            'apr' => '9.9',
            'count_in_costs' => '1',
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function only(App $app, Vehicle $vehicle): FinanceAgreement
    {
        $agreements = $this->service($app, FinanceAgreementRepository::class)->listForVehicle($vehicle->id);
        self::assertCount(1, $agreements);

        return $agreements[0];
    }

    /**
     * @param array<string, string> $form
     * @param App<ContainerInterface> $app
     */
    private function add(App $app, TestBrowser $browser, Vehicle $vehicle, array $form): FinanceAgreement
    {
        $browser->get('/vehicles/' . $vehicle->id . '/finance/new?type=' . $form['type']);
        $response = $browser->post('/vehicles/' . $vehicle->id . '/finance/new', $form);
        self::assertSame(303, $response->getStatusCode(), self::body($response));

        return $this->only($app, $vehicle);
    }

    /**
     * The finance lines of the vehicle's ledger, as the owner sees it.
     *
     * @param App<ContainerInterface> $app
     */
    private function financeTotal(App $app, Vehicle $vehicle): string
    {
        $sum = BigDecimal::zero();
        foreach ($this->service($app, CostLedger::class)->items($this->owner($app), [$vehicle]) as $item) {
            if ($item->source === CostSource::Finance) {
                $sum = $sum->plus($item->amount->toDecimal(2));
            }
        }

        return (string) $sum->toScale(2);
    }

    public function testAPcpFromItsPaperworkShowsTheFigures(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $yaris = $this->vehicle($app, 'Toyota', 'Yaris');

        $header = self::body($browser->get('/vehicles/' . $yaris->id));
        $finance = '/vehicles/' . $yaris->id . '/finance';
        self::assertStringContainsString($finance . '"', $header, 'the Finance tab before any agreement');
        self::assertStringNotContainsString($finance . '/new', $header, 'no Add finance in the header (Phase 33.3)');
        self::assertStringNotContainsString('finance-card-heading', $header, 'no card until there is an agreement');

        $form = self::body($browser->get('/vehicles/' . $yaris->id . '/finance/new?type=pcp'));
        self::assertStringContainsString('Optional final payment (GFV)', $form);
        self::assertStringContainsString('name="annual_mileage_allowance"', $form);
        self::assertStringNotContainsString('name="initial_rental"', $form, 'a lease field');

        $agreement = $this->add($app, $browser, $yaris, self::pcp());
        $page = self::body($browser->get('/vehicles/' . $yaris->id . '/finance/' . $agreement->id));

        self::assertStringContainsString('18 of 36 remaining', $page);
        self::assertStringContainsString('£4,500.00', $page, 'remaining to pay, exact');
        self::assertStringContainsString('plus the optional final payment of £8,000.00', $page);
        self::assertStringContainsString('£12,500.00', $page, 'the settlement at 0%');
        self::assertStringContainsString('Estimated. Your lender’s settlement figure will differ', $page);
        self::assertStringContainsString('•••• 5678', $page);
        self::assertStringNotContainsString('PCP-0012345678', $page, 'the number is masked');
        self::assertStringContainsString('Add a valuation to see your equity', $page);
        self::assertStringContainsString('Half the total amount payable.', $page);
        self::assertStringContainsString('Payment 18', $page);

        $edit = self::body($browser->get('/vehicles/' . $yaris->id . '/finance/' . $agreement->id . '/edit'));
        self::assertStringContainsString('PCP-0012345678', $edit, 'in full on the edit form');

        $overview = self::body($browser->get('/vehicles/' . $yaris->id));
        self::assertStringContainsString('finance-card-heading', $overview);
        self::assertStringContainsString('18 of 36 remaining', $overview);
        $financePage = '/vehicles/' . $yaris->id . '/finance"';
        self::assertStringContainsString($financePage, $overview, 'the Finance tab');
    }

    public function testALendersQuoteReplacesTheEstimateUntilItExpires(): void
    {
        $app = $this->createApp();
        $clock = $this->pinClock($app, '2026-07-15T10:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $agreement = $this->add($app, $browser, $golf, self::hp());
        $url = '/vehicles/' . $golf->id . '/finance/' . $agreement->id;

        self::assertStringContainsString('£5,037.89', self::body($browser->get($url)), 'the worked present value');

        $quote = ['quote_amount' => '7612.08', 'quoted_on' => '2026-07-10', 'valid_until' => '2026-07-31'];
        $browser->post($url . '/quotes', $quote);
        $page = self::body($browser->get($url));
        self::assertStringContainsString('£7,612.08, quoted 10 Jul 2026, valid until 31 Jul 2026', $page);

        $clock->set(new DateTimeImmutable('2026-08-01T10:00:00Z'));
        $page = self::body($browser->get($url));
        self::assertStringContainsString('Estimated. Your lender’s settlement figure will differ', $page);
        self::assertStringContainsString('Expired', $page);
    }

    public function testASecondActiveAgreementIsRefused(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->add($app, $browser, $golf, self::hp());

        $response = $browser->post('/vehicles/' . $golf->id . '/finance/new', self::pcp());
        self::assertSame(303, $response->getStatusCode());
        self::assertCount(1, $this->service($app, FinanceAgreementRepository::class)->listForVehicle($golf->id));
        self::assertStringContainsString(
            'This vehicle already has an active agreement. End it first.',
            self::body($browser->get('/vehicles/' . $golf->id . '/finance')),
        );
    }

    public function testTheConsistencyCheckWarnsButSaves(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $yaris = $this->vehicle($app, 'Toyota', 'Yaris');
        $agreement = $this->add($app, $browser, $yaris, self::pcp(['total_amount_payable' => '20100']));

        self::assertStringContainsString(
            'These figures add up to £20,000.00, but the agreement says £20,100.00. Check the paperwork.',
            self::body($browser->get('/vehicles/' . $yaris->id . '/finance/' . $agreement->id)),
        );
    }

    public function testCreditChargesCountOnceAndNeverCapital(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-07-15T10:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->add($app, $browser, $golf, self::hp(['set_purchase_price' => '1']));

        $vehicle = $this->service($app, VehicleRepository::class)->findById($golf->id);
        self::assertSame('15000.000', $vehicle?->data->purchasePrice, 'the cash price, offered and ticked');
        self::assertSame('2078.28', $this->financeTotal($app, $golf), '30 payments’ interest, never the 9,040.50 of payments');

        $expenses = self::body($browser->get('/vehicles/' . $golf->id . '/expenses?range=all'));
        self::assertStringContainsString('From the finance agreement', $expenses);
        self::assertStringContainsString('/vehicles/' . $golf->id . '/finance/', $expenses);
    }

    public function testCountInCostsOffAddsNothing(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-07-15T10:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->add($app, $browser, $golf, self::hp(['count_in_costs' => '0']));

        self::assertSame('0.00', $this->financeTotal($app, $golf));
    }

    public function testTheOverlapWarningListsManualFinanceExpensesInCoveredMonths(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $yaris = $this->vehicle($app, 'Toyota', 'Yaris');
        $this->expense($app, $yaris, '2025-03-01', '250', ExpenseCategory::Finance, 'PCP March');
        $this->expense($app, $yaris, '2024-11-30', '99', ExpenseCategory::Finance, 'Before the agreement');
        $this->expense($app, $yaris, '2025-03-02', '5', ExpenseCategory::Parking, 'Parking');
        $agreement = $this->add($app, $browser, $yaris, self::pcp());

        $page = self::body($browser->get('/vehicles/' . $yaris->id . '/finance/' . $agreement->id));
        self::assertStringContainsString('may count twice with this agreement', $page);
        self::assertStringContainsString('PCP March', $page);
        self::assertStringNotContainsString('Before the agreement', $page);
        self::assertStringNotContainsString('Parking', $page);

        self::assertStringContainsString('PCP March', self::body($browser->get('/vehicles/' . $yaris->id . '/expenses')));
        self::assertStringContainsString('may count twice', self::body($browser->get('/vehicles/' . $yaris->id . '/expenses')));
    }

    public function testMarkingAPaymentMissedThenPaidLate(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-07-15T10:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $agreement = $this->add($app, $browser, $golf, self::hp());
        $url = '/vehicles/' . $golf->id . '/finance/' . $agreement->id;

        $browser->post($url . '/payments', ['kind' => 'missed', 'due_on' => '2026-06-15']);
        $page = self::body($browser->get($url));
        self::assertStringContainsString('19 of 48 remaining', $page, 'the missed June is still owed');
        self::assertStringContainsString('Undo', $page);
        self::assertSame('2034.41', $this->financeTotal($app, $golf), 'a missed payment adds no interest (43.87) until paid');

        $browser->post($url . '/payments', ['kind' => 'paid_late', 'due_on' => '2026-06-15', 'paid_on' => '2026-06-29']);
        self::assertSame('2078.28', $this->financeTotal($app, $golf));

        $refused = $browser->post($url . '/payments', ['kind' => 'missed', 'due_on' => '2026-09-15']);
        self::assertSame(303, $refused->getStatusCode());
        self::assertStringContainsString('That payment can’t be marked so.', self::body($browser->get($url)), 'not yet due');
    }

    public function testOnlyManageWithCostsSeesTheAgreement(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-07-15T10:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $agreement = $this->add($app, $browser, $golf, self::hp());
        $url = '/vehicles/' . $golf->id . '/finance/' . $agreement->id;
        $shares = $this->service($app, VehicleShareRepository::class);
        $now = new DateTimeImmutable('2026-07-15T10:00:00Z');

        // Manage always brings ViewCosts (ShareLevel), so the gap is below Manage.
        $viewer = $this->createMember($app, 'viewer');
        $shares->insert($golf->id, $viewer->id, ShareLevel::View, true, false, $now);
        $logger = $this->createMember($app, 'logger', displayName: 'Lou Logger');
        $shares->insert($golf->id, $logger->id, ShareLevel::Log, true, false, $now);
        $partner = $this->createMember($app, 'partner2', displayName: 'Pat Partner');
        $shares->insert($golf->id, $partner->id, ShareLevel::Manage, true, false, $now);

        $view = $this->browserFor($app, 'viewer');
        self::assertSame(404, $view->get($url)->getStatusCode(), 'View with ViewCosts');
        self::assertSame(404, $view->get('/vehicles/' . $golf->id . '/finance')->getStatusCode());
        self::assertStringNotContainsString('/finance', self::body($view->get('/vehicles/' . $golf->id)));

        $log = $this->browserFor($app, 'logger');
        self::assertSame(404, $log->get($url)->getStatusCode(), 'Log with ViewCosts');
        $export = $log->get('/vehicles/' . $golf->id . '/export/finance.csv');
        self::assertSame(403, $export->getStatusCode(), 'every export needs Manage');
        $expenses = self::body($log->get('/vehicles/' . $golf->id . '/expenses?range=all'));
        self::assertStringContainsString('Finance and lease', $expenses, 'the lines count for every cost viewer (#125)');
        self::assertStringNotContainsString('From the finance agreement', $expenses);
        self::assertStringNotContainsString('/finance/', $expenses, 'no link to the agreement');
        self::assertStringNotContainsString('Black Horse', $expenses);

        $full = $this->browserFor($app, 'partner2');
        self::assertSame(200, $full->get($url)->getStatusCode(), 'Manage with ViewCosts');
    }

    public function testFinanceIsNeverInTheSalePackOrHistoryPrint(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-07-15T10:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->add($app, $browser, $golf, self::hp());

        self::assertStringNotContainsString('Black Horse', self::body($browser->get('/vehicles/' . $golf->id . '/sale-pack')));
        $print = self::body($browser->get('/vehicles/' . $golf->id . '/history/print'));
        self::assertStringNotContainsString('Black Horse', $print);
    }

    public function testWithTheModuleOffEverythingIsGoneAndTheDataKept(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-07-15T10:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $agreement = $this->add($app, $browser, $golf, self::hp());

        $features = $this->service($app, FeatureToggles::class);
        $features->save(array_values(array_filter(
            Feature::cases(),
            static fn (Feature $feature): bool => $feature !== Feature::Finance && $features->isEnabled($feature),
        )));
        self::assertTrue($features->isEnabled(Feature::Fuel), 'only finance is switched off');

        self::assertSame(404, $browser->get('/vehicles/' . $golf->id . '/finance/' . $agreement->id)->getStatusCode());
        self::assertSame(404, $browser->get('/vehicles/' . $golf->id . '/export/finance.csv')->getStatusCode());
        $overview = self::body($browser->get('/vehicles/' . $golf->id));
        self::assertStringNotContainsString('finance-card-heading', $overview);
        self::assertStringNotContainsString('/finance', $overview);
        self::assertSame('0.00', $this->financeTotal($app, $golf));
        self::assertCount(1, $this->service($app, FinanceAgreementRepository::class)->listForVehicle($golf->id), 'kept');
    }

    public function testTheScheduleAndExportCsvNeverCarryTheAgreementNumber(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $yaris = $this->vehicle($app, 'Toyota', 'Yaris');
        $agreement = $this->add($app, $browser, $yaris, self::pcp());

        $schedule = $browser->get('/vehicles/' . $yaris->id . '/finance/' . $agreement->id . '/schedule.csv');
        self::assertSame(200, $schedule->getStatusCode());
        $csv = self::body($schedule);
        self::assertStringContainsString('2025-01-31', $csv);
        self::assertStringContainsString('Final payment', $csv);
        self::assertStringContainsString('2028-01-31', $csv);
        self::assertStringNotContainsString('12345678', $csv);
        self::assertSame(1 + 37, count(array_filter(explode("\n", trim($csv)))), 'a header, 36 payments and the final one');

        $export = self::body($browser->get('/vehicles/' . $yaris->id . '/export/finance.csv'));
        self::assertStringContainsString('Toyota Financial Services', $export);
        self::assertStringNotContainsString('12345678', $export);
    }
}
