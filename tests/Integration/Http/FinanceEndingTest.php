<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Expense\CostSource;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\Vehicle\Disposal;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Repository\ReminderRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Ai\Provider\ToolCall;
use Logbook\Service\Expense\CostLedger;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Phase 29.2 end to end (spec.md §7.32): mileage against the allowance and
 * its *Needs attention* item, ending an agreement every way through its
 * form and the archive page, *Coming up* lines, finance reminders, missed
 * payments as *Now* items, the widget, the API endpoint and the Ask tool,
 * with §7.32's access and the module switch.
 */
final class FinanceEndingTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** The PCP's 18th payment is due on 30 Jun 2026. */
    private const string NOW = '2026-06-30T10:00:00Z';

    /**
     * A 0% PCP: cash price 20,000, deposits 3,000, 36 payments of 250 from
     * 31 Jan 2025 and an optional final payment of 8,000 on 31 Jan 2028;
     * 8,000 mi a year at 9p over its 37 months.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function pcp(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'pcp',
            'lender' => 'Toyota Financial Services',
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
            'set_purchase_price' => '1',
        ];
    }

    /**
     * HP at 9.9%: 48 payments of 301.35 from 15 Feb 2024.
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
            'set_purchase_price' => '1',
        ];
    }

    /**
     * A lease: an initial rental of 1,800 on 10 Mar 2025, then 35 rentals of
     * 300 from 10 Apr 2025; 10,000 mi a year at 8p.
     *
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
            'annual_mileage_allowance' => '10000',
            'mileage_unit' => 'mi',
            'excess_mileage_charge' => '0.08',
            'count_in_costs' => '1',
        ];
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
        $agreement = $this->service($app, FinanceAgreementRepository::class)->activeFor($vehicle->id);
        self::assertNotNull($agreement);

        return $agreement;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function reload(App $app, FinanceAgreement $agreement): FinanceAgreement
    {
        $fresh = $this->service($app, FinanceAgreementRepository::class)->find($agreement->vehicleId, $agreement->id);
        self::assertNotNull($fresh);

        return $fresh;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function vehicleRow(App $app, Vehicle $vehicle): Vehicle
    {
        $fresh = $this->service($app, VehicleRepository::class)->findById($vehicle->id);
        self::assertNotNull($fresh);

        return $fresh;
    }

    /**
     * Miles as the canonical km a reading stores.
     */
    private static function miles(int $miles): string
    {
        return number_format($miles * DistanceUnit::KM_PER_MILE, 3, '.', '');
    }

    /**
     * The finance lines of the vehicle's cost ledger, as the owner sees it.
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

    /**
     * The vehicle's finance reminders after a sync, by source.
     *
     * @param App<ContainerInterface> $app
     * @return array<string, Reminder>
     */
    private function financeReminders(App $app, Vehicle $vehicle): array
    {
        $this->service($app, ReminderSync::class)->sync($this->owner($app));
        $found = [];
        foreach ($this->service($app, ReminderRepository::class)->listForVehicles([$vehicle->id]) as $reminder) {
            if ($reminder->source->isFinance()) {
                $found[$reminder->source->value] = $reminder;
            }
        }

        return $found;
    }

    /**
     * @return array<string, string> the archive form's values, without the token
     */
    private static function archiveForm(string $html): array
    {
        $values = Html::formValues(Html::element(Html::document($html), 'form[action$="/archive"]'));
        unset($values['csrf_name'], $values['csrf_value']);

        return $values;
    }

    public function testAPcpHeadingOverShowsTheExcessOnTheCardAndInNeedsAttention(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $yaris = $this->vehicle($app, 'Toyota', 'Yaris');
        // 15,000 mi in 546 days: on for about 30,900 mi against 24,667 (37 months of 8,000 a year).
        $this->reading($app, $yaris, self::miles(1000), '2024-12-31T09:00:00Z');
        $this->reading($app, $yaris, self::miles(16000), '2026-06-30T09:00:00Z');
        $agreement = $this->add($app, $browser, $yaris, self::pcp());

        $page = self::body($browser->get('/vehicles/' . $yaris->id . '/finance/' . $agreement->id));
        self::assertStringContainsString('On track for 30,900 mi against 24,667 mi.', $page);
        self::assertStringContainsString('About £564 in excess mileage at £0.09 a mile.', $page);
        self::assertStringContainsString('15,000 mi so far', $page);

        $overview = self::body($browser->get('/vehicles/' . $yaris->id));
        self::assertStringContainsString('On track for 30,900 mi against 24,667 mi.', $overview, 'on the card');
        self::assertStringContainsString('Heading for about 6,300 mi over your allowance: about £564', $overview);

        // Hidden by its fingerprint, until the projection moves by about 100.
        $form = Html::formValues(Html::element(Html::document($overview), 'form[action$="/attention/hide"]'));
        unset($form['csrf_name'], $form['csrf_value']);
        self::assertSame('finance_mileage', $form['kind']);
        $browser->post('/vehicles/' . $yaris->id . '/attention/hide', $form);
        self::assertStringNotContainsString('over your allowance', self::body($browser->get('/vehicles/' . $yaris->id)));
        $this->reading($app, $yaris, self::miles(17500), '2026-06-30T18:00:00Z');
        self::assertStringContainsString('over your allowance', self::body($browser->get('/vehicles/' . $yaris->id)), 'moved');
    }

    public function testUnderTheAllowanceOrWithoutReadingsThereIsNoAttentionItem(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $yaris = $this->vehicle($app, 'Toyota', 'Yaris');
        $agreement = $this->add($app, $browser, $yaris, self::pcp());
        $url = '/vehicles/' . $yaris->id . '/finance/' . $agreement->id;

        self::assertStringContainsString('Add a mileage reading to see where you stand.', self::body($browser->get($url)));

        $this->reading($app, $yaris, self::miles(1000), '2024-12-31T09:00:00Z');
        $this->reading($app, $yaris, self::miles(9000), '2026-06-30T09:00:00Z');
        self::assertStringContainsString('under the allowance', self::body($browser->get($url)));
        self::assertStringNotContainsString('over your allowance', self::body($browser->get('/vehicles/' . $yaris->id)));
    }

    public function testSettlingEarlyStopsTheScheduleAndMarksTheRemindersDone(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $agreement = $this->add($app, $browser, $golf, self::pcp(['lender' => 'VW Finance']));
        self::assertSame(['finance', 'finance_end'], array_keys($this->financeReminders($app, $golf)));

        $form = self::body($browser->get('/vehicles/' . $golf->id . '/finance/' . $agreement->id . '/end'));
        self::assertStringContainsString('name="settlement" type="number" value="12500.00"', $form, 'from the estimate');
        self::assertStringContainsString('value="handed_back"', $form);
        self::assertStringNotContainsString('value="ended"', $form, 'a lease outcome');

        $response = $browser->post('/vehicles/' . $golf->id . '/finance/' . $agreement->id . '/end', [
            'outcome' => 'settled',
            'ended_on' => '2026-06-30',
            'settlement' => '12400',
        ]);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringEndsWith('/finance/' . $agreement->id, $response->getHeaderLine('Location'));

        $ended = $this->reload($app, $agreement);
        self::assertSame(AgreementStatus::Settled, $ended->status);
        self::assertSame('2026-06-30', $ended->endedOn?->format('Y-m-d'));
        $page = self::body($browser->get('/vehicles/' . $golf->id . '/finance/' . $agreement->id));
        self::assertStringContainsString('Payment 18', $page);
        self::assertStringNotContainsString('Payment 19', $page, 'later payments leave the schedule');
        foreach ($this->financeReminders($app, $golf) as $reminder) {
            self::assertSame(ReminderStatus::Done, $reminder->status, $reminder->source->value);
        }
        // Exact: deposits 3,000 + 18 × 250 + 12,400 − 20,000.
        self::assertSame('-100.00', $this->financeTotal($app, $golf));
        self::assertSame(404, $browser->get('/vehicles/' . $golf->id . '/finance/' . $agreement->id . '/end')->getStatusCode());
    }

    public function testCompletingIsNotBeforeTheLastPayment(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2028-03-01T10:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $agreement = $this->add($app, $browser, $golf, self::hp());
        $url = '/vehicles/' . $golf->id . '/finance/' . $agreement->id . '/end';

        $early = $browser->post($url, ['outcome' => 'completed', 'ended_on' => '2027-12-01']);
        self::assertSame(422, $early->getStatusCode());
        self::assertStringContainsString('A completed agreement ends on or after its last payment.', self::body($early));

        self::assertSame(303, $browser->post($url, ['outcome' => 'completed', 'ended_on' => '2028-01-15'])->getStatusCode());
        self::assertSame(AgreementStatus::Completed, $this->reload($app, $agreement)->status);
    }

    public function testHandingBackArchivesAtTheFinalPaymentSoLifetimeCostIsExact(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2028-02-10T10:00:00Z');
        $browser = $this->signedIn($app);
        $yaris = $this->vehicle($app, 'Toyota', 'Yaris');
        $this->reading($app, $yaris, self::miles(1000), '2024-12-31T09:00:00Z');
        $this->reading($app, $yaris, self::miles(26000), '2028-01-31T09:00:00Z');
        $agreement = $this->add($app, $browser, $yaris, self::pcp());
        $url = '/vehicles/' . $yaris->id . '/finance/' . $agreement->id . '/end';

        // 25,000 mi against 24,667: 333 mi over at 9p.
        self::assertStringContainsString('name="excess_charge" type="number" value="30.00"', self::body($browser->get($url)));
        $response = $browser->post($url, [
            'outcome' => 'handed_back',
            'ended_on' => '2028-01-31',
            'excess_charge' => '30.00',
            'damage_charge' => '150',
        ]);
        self::assertSame(303, $response->getStatusCode());
        $archiveUrl = '/vehicles/' . $yaris->id . '/archive?disposal=returned_lender';
        self::assertStringContainsString($archiveUrl, $response->getHeaderLine('Location'));

        $charges = array_values(array_filter(
            $this->service($app, ExpenseEntryRepository::class)->listForVehicle($yaris->id),
            static fn ($entry): bool => $entry->data->category === ExpenseCategory::Finance,
        ));
        self::assertCount(2, $charges, 'excess mileage and damage, as expenses');
        self::assertSame('2028-01-31', $charges[0]->data->spentOn->format('Y-m-d'));
        $page = self::body($browser->get('/vehicles/' . $yaris->id . '/finance/' . $agreement->id));
        self::assertStringNotContainsString('may count twice', $page, 'the charges never overlap (#127)');

        $archive = self::body($browser->get($response->getHeaderLine('Location')));
        self::assertStringContainsString('The sale price is the optional final payment, £8,000.00', $archive);
        $form = self::archiveForm($archive);
        self::assertSame('returned_lender', $form['disposal']);
        self::assertSame(303, $browser->post('/vehicles/' . $yaris->id . '/archive', $form)->getStatusCode());

        $archived = $this->vehicleRow($app, $yaris);
        self::assertTrue($archived->isArchived());
        self::assertSame(Disposal::ReturnedLender, $archived->disposal);
        self::assertSame('8000.000', $archived->data->salePrice);
        self::assertSame('2028-01-31', $archived->data->saleDate?->format('Y-m-d'));
        // Paid 3,000 + 36 × 250 = 12,000 = cash price 20,000 − final payment 8,000: no cost of credit at 0%,
        // so the lifetime cost is 20,000 − 8,000 + the charges.
        self::assertSame('0.00', $this->financeTotal($app, $yaris));
        $overview = self::body($browser->get('/vehicles/' . $yaris->id));
        self::assertStringContainsString('Returned to the lender', $overview);
        self::assertStringContainsString('Lifetime, returned to the lender', $overview);
        self::assertStringContainsString('£12,180.00', $overview, 'cash price − final payment + the charges');
    }

    public function testEndingALeaseLogsTheChargesAndArchivesAsReturnedToTheLessor(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2028-03-15T10:00:00Z');
        $browser = $this->signedIn($app);
        $kia = $this->vehicle($app, 'Kia', 'EV6');
        $agreement = $this->add($app, $browser, $kia, self::lease());

        $form = self::body($browser->get('/vehicles/' . $kia->id . '/finance/' . $agreement->id . '/end'));
        self::assertStringContainsString('value="ended"', $form);
        self::assertStringNotContainsString('value="settled"', $form, 'a lease is never settled');

        $response = $browser->post('/vehicles/' . $kia->id . '/finance/' . $agreement->id . '/end', [
            'outcome' => 'ended',
            'ended_on' => '2028-03-10',
            'excess_charge' => '',
            'damage_charge' => '95',
        ]);
        self::assertStringContainsString('disposal=returned_lessor', $response->getHeaderLine('Location'));
        self::assertSame(AgreementStatus::Ended, $this->reload($app, $agreement)->status);

        $archive = self::body($browser->get($response->getHeaderLine('Location')));
        self::assertStringContainsString('A leased car has no sale price.', $archive);
        self::assertSame(303, $browser->post('/vehicles/' . $kia->id . '/archive', self::archiveForm($archive))->getStatusCode());
        $archived = $this->vehicleRow($app, $kia);
        self::assertSame(Disposal::ReturnedLessor, $archived->disposal);
        self::assertNull($archived->data->salePrice);
        self::assertSame('2028-03-10', $archived->data->saleDate?->format('Y-m-d'));
    }

    public function testSellingWithFinanceOwingWarnsAndSettlesFromTheSale(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-07-15T10:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $agreement = $this->add($app, $browser, $golf, self::hp());

        $header = self::body($browser->get('/vehicles/' . $golf->id));
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/archive"', $header, 'Archive opens the page');

        $archive = self::body($browser->get('/vehicles/' . $golf->id . '/archive'));
        self::assertStringContainsString('This agreement is still active. The lender owns the car until', $archive);
        self::assertStringContainsString('Settled from the sale', $archive);
        self::assertStringNotContainsString('value="returned_lender"', $archive, 'not a PCP');

        $form = ['disposal' => 'sold', 'sale_date' => '2026-07-10', 'sale_price' => '9500'] + self::archiveForm($archive);
        $form['settle_from_sale'] = '1';
        $form['settlement'] = '7612.08';
        self::assertSame(303, $browser->post('/vehicles/' . $golf->id . '/archive', $form)->getStatusCode());

        $settled = $this->reload($app, $agreement);
        self::assertSame(AgreementStatus::Settled, $settled->status);
        self::assertSame('2026-07-10', $settled->endedOn?->format('Y-m-d'));
        $archived = $this->vehicleRow($app, $golf);
        self::assertSame(Disposal::Sold, $archived->disposal);
        self::assertSame('9500.000', $archived->data->salePrice);
    }

    public function testJustArchivingLeavesTheAgreementActive(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-07-15T10:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $agreement = $this->add($app, $browser, $golf, self::hp());

        self::assertSame(303, $browser->post('/vehicles/' . $golf->id . '/archive', ['disposal' => ''])->getStatusCode());
        self::assertTrue($this->vehicleRow($app, $golf)->isArchived());
        self::assertNull($this->vehicleRow($app, $golf)->disposal);
        self::assertSame(AgreementStatus::Active, $this->reload($app, $agreement)->status);
    }

    public function testComingUpCountsTheNextTwelveMonthsAndTheFinalPayment(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2027-03-15T10:00:00Z');
        $browser = $this->signedIn($app);
        $yaris = $this->vehicle($app, 'Toyota', 'Yaris');
        $this->add($app, $browser, $yaris, self::pcp());

        $page = self::body($browser->get('/upcoming'));
        // Payments 27 to 36 (31 Mar to 31 Dec 2027) and the final payment on 31 Jan 2028.
        self::assertStringContainsString('Finance payments, 10 × £250.00', $page);
        self::assertStringContainsString('Final payment', $page);
        self::assertStringContainsString('£10,500.00', $page, '2,500 + 8,000 in the planned total');

        // Below Manage with costs: the same lines, plain (#128).
        $logger = $this->createMember($app, 'logger');
        $this->service($app, VehicleShareRepository::class)
            ->insert($yaris->id, $logger->id, ShareLevel::Log, true, false, new DateTimeImmutable(self::NOW));
        $plain = self::body($this->browserFor($app, 'logger')->get('/upcoming'));
        self::assertStringContainsString('Finance payments, 10 × £250.00', $plain);
        self::assertStringNotContainsString('/finance/', $plain, 'no link to the agreement');
    }

    public function testAMissedPaymentIsANowItemUntilPaidLate(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $agreement = $this->add($app, $browser, $golf, self::hp());
        $payments = '/vehicles/' . $golf->id . '/finance/' . $agreement->id . '/payments';

        // A reading that goes backwards is a check; the missed payment, Now, comes first.
        $this->reading($app, $golf, '20000', '2026-05-01T09:00:00Z');
        $this->reading($app, $golf, '19000', '2026-06-01T09:00:00Z');
        $browser->post($payments, ['kind' => 'missed', 'due_on' => '2026-06-15']);
        $overview = self::body($browser->get('/vehicles/' . $golf->id));
        $missed = strpos($overview, 'Finance payment due 15 Jun 2026 marked missed');
        self::assertNotFalse($missed);
        $items = Html::document($overview)->getElementsByTagName('li');
        $first = null;
        foreach ($items as $li) {
            if (str_contains((string) $li->getAttribute('class'), 'attention-item')) {
                $first = $li->textContent;
                break;
            }
        }
        self::assertStringContainsString('marked missed', (string) $first, 'Now before Check');

        $browser->post($payments, ['kind' => 'paid_late', 'due_on' => '2026-06-15', 'paid_on' => '2026-06-28']);
        self::assertStringNotContainsString('marked missed', self::body($browser->get('/vehicles/' . $golf->id)));
    }

    public function testTheWidgetApiAndAskNeedManageAndCosts(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);
        $golf = $this->vehicle($app);
        $this->reading($app, $golf, self::miles(1000), '2024-12-31T09:00:00Z');
        $this->reading($app, $golf, self::miles(16000), '2026-06-30T09:00:00Z');
        $this->add($app, $browser, $golf, self::pcp(['lender' => 'VW Finance']));

        $home = self::body($browser->get('/'));
        self::assertStringContainsString('id="widget-finance"', $home);
        self::assertStringContainsString('18 payments remaining · £4,500.00 to pay · ends Jan 2028', $home);

        $api = $this->api($app, $this->apiKey($app, $owner));
        $response = $api->get('/vehicles/' . $golf->id . '/finance');
        self::assertSame(200, $response->getStatusCode());
        ApiClient::assertMatchesContract('GET', '/vehicles/{id}/finance', $response);
        $json = ApiClient::json($response);
        self::assertSame('pcp', $json->get('agreement', 'type'));
        self::assertTrue($json->get('agreement', 'settlement', 'estimate'));
        self::assertStringNotContainsString('agreement_number', (string) $response->getBody());

        $run = $this->service($app, ToolRegistry::class)->run($owner, new ToolCall('c1', 'finance', ['vehicle' => $golf->id]));
        self::assertNull($run->error);

        // A log share with costs: nothing about finance but the plain cost and Coming up lines.
        $logger = $this->createMember($app, 'logger');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $logger->id, ShareLevel::Log, true, true, new DateTimeImmutable(self::NOW));
        $theirs = $this->browserFor($app, 'logger');
        self::assertStringNotContainsString('id="widget-finance"', self::body($theirs->get('/')));
        self::assertStringNotContainsString('over your allowance', self::body($theirs->get('/vehicles/' . $golf->id)));
        $theirApi = $this->api($app, $this->apiKey($app, $logger));
        self::assertSame(404, $theirApi->get('/vehicles/' . $golf->id . '/finance')->getStatusCode());
        $denied = $this->service($app, ToolRegistry::class)
            ->run($logger, new ToolCall('c2', 'finance', ['vehicle' => $golf->id]));
        self::assertNotNull($denied->error);
        $entries = $this->service($app, ReminderService::class)->overview($logger);
        foreach ([...$entries->open, ...$entries->closed] as $entry) {
            self::assertFalse($entry->reminder->source->isFinance(), 'no finance reminders below Manage');
        }
        self::assertFalse($this->service($app, FinanceService::class)->canSee($logger, $golf));
    }

    public function testWithTheModuleOffItAllGoesAndTheDataIsKept(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->reading($app, $golf, self::miles(1000), '2024-12-31T09:00:00Z');
        $this->reading($app, $golf, self::miles(16000), '2026-06-30T09:00:00Z');
        $agreement = $this->add($app, $browser, $golf, self::pcp());
        self::assertCount(2, $this->financeReminders($app, $golf));

        $features = $this->service($app, FeatureToggles::class);
        $features->save(array_values(array_filter(
            Feature::cases(),
            static fn (Feature $feature): bool => $feature !== Feature::Finance && $features->isEnabled($feature),
        )));

        self::assertStringNotContainsString('id="widget-finance"', self::body($browser->get('/')));
        self::assertStringNotContainsString('Finance payments', self::body($browser->get('/upcoming')));
        self::assertStringNotContainsString('over your allowance', self::body($browser->get('/vehicles/' . $golf->id)));
        $overview = $this->service($app, ReminderService::class)->overview($this->owner($app));
        foreach ($overview->open as $entry) {
            self::assertFalse($entry->reminder->source->isFinance(), 'not listed with the module off');
        }
        $api = $this->api($app, $this->apiKey($app, $this->owner($app)));
        self::assertSame(404, $api->get('/vehicles/' . $golf->id . '/finance')->getStatusCode());
        self::assertCount(2, $this->financeReminders($app, $golf), 'kept');
        self::assertSame(AgreementStatus::Active, $this->reload($app, $agreement)->status);
        self::assertSame(ReminderSource::Finance, $this->financeReminders($app, $golf)['finance']->source);
    }
}
