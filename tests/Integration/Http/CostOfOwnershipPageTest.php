<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Expense\CostSource;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Expense\CostLedger;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Finance\FinanceLineKind;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The Cost of ownership page's screen and the vehicle's Cost of ownership
 * tab (spec.md §7.1, §7.7 *Cost of ownership page*, Phase 33.4): summary
 * cards that add up the vehicle cards, per currency; *Per month* over the
 * active vehicles; *Finance interest* from the ledger's credit charges;
 * the bar's parts; access. The owner uses UK units and GBP; "today" is
 * 1 Sep 2026.
 */
final class CostOfOwnershipPageTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-01T10:00:00Z';

    public function testTheSummaryAddsUpTheVehicleCards(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);
        $this->fiesta($app);

        $html = self::body($browser->get('/reports/ownership?include_archived=1'));
        $screen = self::screen($html);

        self::assertStringContainsString('£16,900.00', self::cardFor($screen, $golf), 'running £11,700 + depreciation £5,200');
        self::assertStringContainsString('£7,400.00', $screen, 'the Fiesta: £3,000 running + £4,400 lost');
        $stats = self::stats($screen);
        self::assertStringContainsString('£24,300.00', $stats, 'Total cost: both cards');
        self::assertStringContainsString('2 vehicles since purchase', $stats);
        self::assertStringContainsString('£9,600.00', $stats, 'Depreciation: £5,200 + £4,400');
        self::assertStringContainsString('40% of total', $stats);
        self::assertStringContainsString('£416.54', $stats, 'Per month: the Golf alone, the Fiesta is sold (#187)');
        self::assertStringContainsString('active vehicles combined', $stats);
        self::assertStringContainsString('No finance', $stats);
        self::assertLessThan(
            strpos($screen, 'Fiesta'),
            strpos($screen, 'Golf'),
            'highest total first',
        );

        $golfOnly = self::stats(self::screen(self::body($browser->get('/reports/ownership?vehicle=' . $golf->id))));
        self::assertStringContainsString('£16,900.00', $golfOnly, 'the vehicle filter');
        self::assertStringContainsString('1 vehicle since purchase', $golfOnly);
    }

    public function testEachCurrencyHasItsOwnSummaryNeverConverted(): void
    {
        [$app, $browser] = $this->start();
        $this->golf($app);
        $seat = $this->car($app, 'Seat', 'Leon', currency: 'EUR', purchased: '2025-01-01', price: '20000');
        $this->expense($app, $seat, '2025-06-01', '1234.50');
        $this->service($app, ValuationService::class)->create($seat, new VehicleValuationData(self::date('2026-01-01'), '17000'));

        $screen = self::screen(self::body($browser->get('/reports/ownership')));

        self::assertSame(2, substr_count($screen, 'class="stats ownership-page__stats"'), 'one summary per currency');
        self::assertStringContainsString('In British Pound', $screen);
        self::assertStringContainsString('In Euro', $screen);
        self::assertStringContainsString('€4,234.50', $screen, 'the Leon’s own total, in euros');
        self::assertStringNotContainsString('£21,134.50', $screen, 'never added across currencies');
    }

    public function testFinanceInterestCountsCreditChargesSoFarNotLeaseRentals(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);
        $this->agreement($app, $golf, AgreementType::Hp, documentationFee: '99');
        $kia = $this->car($app, 'Kia', 'EV6', purchased: '2025-01-01');
        $this->agreement($app, $kia, AgreementType::Lease);

        $stats = self::stats(self::screen(self::body($browser->get('/reports/ownership'))));

        $expected = Money::zero('GBP');
        $rentals = Money::zero('GBP');
        foreach ($this->service($app, CostLedger::class)->items($this->owner($app), [$golf, $kia]) as $item) {
            if ($item->source !== CostSource::Finance || $item->date > self::date('2026-09-01')) {
                continue;
            }
            if ($item->financeKind === FinanceLineKind::Rental || $item->fromLease) {
                $rentals = $rentals->add($item->amount);
            } else {
                $expected = $expected->add($item->amount);
            }
        }
        self::assertFalse($expected->isZero(), 'the HP has interest and its fee by now');
        self::assertFalse($rentals->isZero(), 'the lease has rentals by now');
        self::assertStringContainsString('Finance interest', $stats);
        self::assertStringContainsString(self::money($expected), $stats, 'interest and fees counted so far');
        self::assertStringContainsString('interest and fees paid so far', $stats);
        self::assertStringNotContainsString(self::money($expected->add($rentals)), $stats, 'lease rentals are not interest');
        self::assertStringNotContainsString('No finance', $stats);
    }

    public function testALeaseAloneIsNoFinance(): void
    {
        [$app, $browser] = $this->start();
        $kia = $this->car($app, 'Kia', 'EV6', purchased: '2025-01-01');
        $this->agreement($app, $kia, AgreementType::Lease, documentationFee: '250');

        $stats = self::stats(self::screen(self::body($browser->get('/reports/ownership'))));

        self::assertStringContainsString('No finance', $stats, 'a lease’s fee is not interest either');
    }

    public function testTheBarIsTheFivePartsAndAGainIsListedUnderIt(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);
        $classic = $this->car($app, 'Morris', 'Minor', purchased: '2024-01-01', price: '5000');
        $this->expense($app, $classic, '2024-06-01', '800');
        $this->service($app, ValuationService::class)
            ->create($classic, new VehicleValuationData(self::date('2026-06-01'), '6500'));

        $screen = self::screen(self::body($browser->get('/reports/ownership')));

        $card = self::cardFor($screen, $golf);
        self::assertSame(3, substr_count($card, 'class="stack-bar__part"'), 'fuel, other and depreciation');
        self::assertStringContainsString('Fuel</span><span class="legend__value tabular">£7,000.00 · 41%', $card);
        self::assertStringContainsString('Other</span><span class="legend__value tabular">£4,700.00 · 28%', $card);
        self::assertStringContainsString('Depreciation</span><span class="legend__value tabular">£5,200.00 · 31%', $card);
        self::assertMatchesRegularExpression(
            '/--w: 41\.4%.*--w: 30\.8%.*--w: 27\.8%/s',
            $card,
            'largest first, adding up to 100%',
        );
        self::assertStringContainsString('£0.444/mi over 39,000 mi', $card);

        $minor = self::cardFor($screen, $classic);
        self::assertSame(1, substr_count($minor, 'class="stack-bar__part"'), 'a gain is never drawn');
        self::assertStringContainsString('Gain in value', $minor);
        self::assertStringContainsString('-£1,500.00', $minor);
        self::assertStringContainsString('-£700.00', $minor, 'the total: £800 spent, £1,500 gained');
    }

    public function testASwitchedOffModuleRemovesItsPart(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);
        $this->service($app, SettingRepository::class)->save(FeatureToggles::SETTING, ['fuel' => false], SettingScope::Global);

        $card = self::cardFor(self::screen(self::body($browser->get('/reports/ownership'))), $golf);

        self::assertStringNotContainsString('Fuel</span>', $card);
        self::assertStringContainsString('£9,900.00', $card, '£4,700 other + £5,200 depreciation');
    }

    public function testPrintAndCsvKeepTheTable(): void
    {
        [$app, $browser] = $this->start();
        $this->golf($app);

        $html = self::body($browser->get('/reports/ownership'));

        $print = substr($html, (int) strpos($html, '<div class="print-only">'));
        self::assertStringContainsString('<table', $print, 'the table is the print view');
        self::assertStringContainsString('£16,900.00', $print);
        self::assertStringContainsString('class="no-print ownership-page"', $html);
        self::assertSame(200, $browser->get('/reports/ownership.csv')->getStatusCode());
    }

    public function testTheVehicleTabShowsTheSameFigures(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);

        $html = self::body($browser->get('/vehicles/' . $golf->id . '/ownership'));

        $tab = self::between($html, 'href="/vehicles/' . $golf->id . '/ownership"', '>');
        self::assertStringContainsString('aria-current="page"', $tab);
        self::assertStringContainsString('£16,900.00', $html);
        self::assertStringContainsString('since purchase', $html);
        self::assertStringContainsString('£416.54', $html);
        self::assertStringContainsString('£0.444/mi', $html);
        self::assertStringContainsString('39,000 mi driven', $html);
        self::assertStringContainsString('3 yrs 6 mo', $html);
        self::assertStringContainsString('since Mar 2023', $html);
        self::assertStringContainsString('over 42 months since buying it on 1 Mar 2023', $html);
        self::assertStringContainsString('How it’s worked out', $html);
        self::assertStringContainsString('with its latest value', $html);
        self::assertStringNotContainsString('Add purchase price', $html, 'it has one');

        $kia = $this->car($app, 'Kia', 'EV6', purchased: '2025-01-01');
        $this->expense($app, $kia, '2025-02-01', '449', ExpenseCategory::Finance, 'Lease payment');
        $lease = self::body($browser->get('/vehicles/' . $kia->id . '/ownership'));
        self::assertStringContainsString('running costs only', $lease);
        self::assertStringContainsString('Add purchase price', $lease);
    }

    public function testWithoutCostAccessThereIsNoCardAndNoTab(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);
        $viewer = $this->createMember($app, 'viewer');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable(self::NOW));
        $theirs = $this->browserFor($app, 'viewer');

        $html = self::body($theirs->get('/reports/ownership'));
        self::assertStringNotContainsString('data-ownership-card', $html);
        self::assertStringNotContainsString('£16,900.00', $html);
        $overview = self::body($theirs->get('/vehicles/' . $golf->id));
        self::assertStringNotContainsString('/vehicles/' . $golf->id . '/ownership', $overview, 'no tab');
        self::assertSame(403, $theirs->get('/vehicles/' . $golf->id . '/ownership')->getStatusCode());
        $mine = self::body($browser->get('/reports/ownership'));
        self::assertStringContainsString('data-ownership-card', $mine, 'the owner still sees it');
    }

    /**
     * The worked example of OwnershipCostTest: bought £15,000 on 1 Mar 2023,
     * valued £9,800 on 1 Mar 2026, £7,000 of fuel and £4,700 other, 39,000 mi.
     *
     * @param App<ContainerInterface> $app
     */
    private function golf(App $app): Vehicle
    {
        $golf = $this->car($app, purchased: '2023-03-01', price: '15000');
        $this->reading($app, $golf, '10000', '2023-03-01T12:00:00Z');
        $this->fillUp($app, $golf, '2023-06-01T08:00:00Z', '12000', '4000', '7000.00');
        $this->expense($app, $golf, '2024-05-10', '4700', ExpenseCategory::Other, 'Everything else');
        $this->reading($app, $golf, '67936.384', '2026-03-01T12:00:00Z');
        $this->reading($app, $golf, '72764.416', '2026-08-31T12:00:00Z');
        $this->service($app, ValuationService::class)->create($golf, new VehicleValuationData(self::date('2026-03-01'), '9800'));

        return $golf;
    }

    /**
     * Bought £6,500, sold £2,100, £3,000 running.
     *
     * @param App<ContainerInterface> $app
     */
    private function fiesta(App $app): Vehicle
    {
        $fiesta = $this->car(
            $app,
            'Ford',
            'Fiesta',
            purchased: '2016-06-30',
            price: '6500',
            sold: '2025-11-20',
            salePrice: '2100',
        );
        $this->expense($app, $fiesta, '2020-01-01', '3000');

        return $fiesta;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function agreement(App $app, Vehicle $vehicle, AgreementType $type, ?string $documentationFee = null): void
    {
        $lease = $type === AgreementType::Lease;
        $this->service($app, FinanceAgreementRepository::class)->insert($vehicle->id, new AgreementData(
            type: $type,
            lender: 'Lender',
            agreementNumber: null,
            startedOn: self::date('2025-03-01'),
            firstPaymentOn: self::date('2025-04-01'),
            numberOfPayments: 36,
            regularPayment: $lease ? '349' : '250',
            cashPrice: $lease ? null : '9000',
            initialRental: $lease ? '1047' : null,
            apr: $lease ? '0' : '6.9',
            documentationFee: $documentationFee,
        ), new DateTimeImmutable(self::NOW), $this->owner($app)->id);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function car(
        App $app,
        string $make = 'Volkswagen',
        string $model = 'Golf',
        ?string $currency = null,
        ?string $purchased = null,
        ?string $price = null,
        ?string $sold = null,
        ?string $salePrice = null,
    ): Vehicle {
        return $this->service($app, VehicleService::class)->create($this->owner($app), new VehicleData(
            VehicleType::Car,
            $make,
            $model,
            FuelType::Petrol,
            registration: strtoupper(substr($model, 0, 2)) . '19 ABC',
            currency: $currency,
            purchaseDate: $purchased === null ? null : self::date($purchased),
            purchasePrice: $price,
            saleDate: $sold === null ? null : self::date($sold),
            salePrice: $salePrice,
        ));
    }

    /**
     * @return array{0: App<ContainerInterface>, 1: TestBrowser}
     */
    private function start(): array
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);

        return [$app, $this->signedIn($app)];
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }

    private static function money(Money $amount): string
    {
        return '£' . number_format((float) $amount->toDecimal(2), 2);
    }

    /** The screen's cards, not the print table. */
    private static function screen(string $html): string
    {
        return self::between($html, 'data-ownership-cards', '<div class="print-only">');
    }

    private static function stats(string $screen): string
    {
        return self::between($screen, 'class="stats ownership-page__stats"', '</dl>');
    }

    private static function cardFor(string $screen, Vehicle $vehicle): string
    {
        return self::between($screen, 'data-ownership-card="' . $vehicle->id . '"', '</article>');
    }

    private static function between(string $html, string $from, string $to): string
    {
        $at = strpos($html, $from);
        self::assertNotFalse($at, $from);
        $end = strpos($html, $to, $at + strlen($from));

        return substr($html, $at, ($end === false ? strlen($html) : $end) - $at);
    }
}
