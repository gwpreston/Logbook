<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\User\User;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * `?include=working` on the API (spec.md §7.20, §8 *Show the working*): the
 * working beside the figures it explains, only on request, checked against
 * openapi.json by ApiClient, and a typo in `include` is a 400.
 */
final class ApiWorkingTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-10-05T12:00:00Z');
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->service($this->app, VehicleService::class)->create(
            $this->owner,
            new VehicleData(
                VehicleType::Car,
                'Volkswagen',
                'Golf',
                FuelType::Petrol,
                registration: 'GO19 ABC',
                purchaseDate: LocalTime::parseDate('2024-01-01'),
                purchasePrice: '15000.00',
            ),
        );
        $this->reading($this->app, $this->golf, '1000', '2024-01-01T09:00:00Z');
        $this->fillUp($this->app, $this->golf, '2024-12-31T09:00:00Z', '11000', '700', '1050.00');
        $this->fillUp($this->app, $this->golf, '2025-10-15T09:00:00Z', '19000', '520', '832.00');
        $this->fillUp($this->app, $this->golf, '2026-09-30T09:00:00Z', '26000', '455', '728.00');
        $this->document($this->app, $this->golf, ComplianceType::Insurance, '2025-03-01', '2026-02-28', '365.00');
        $this->service($this->app, ValuationService::class)->create(
            $this->golf,
            new VehicleValuationData(self::date('2026-03-01'), '9800'),
        );
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testTrueCostGivesItsWorkingOnRequestOnly(): void
    {
        $plain = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/true-cost'));
        self::assertNull($plain->get('true_cost', 'working'), 'not unless asked');

        $doc = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/true-cost?include=working'));

        self::assertSame($doc->get('true_cost', 'per_distance'), $doc->get('true_cost', 'working', 'result_value'));
        self::assertNotSame([], $doc->get('true_cost', 'working', 'steps'));
        self::assertNotSame([], $doc->get('true_cost', 'working', 'inputs'));
        self::assertIsArray($doc->get('years', 1, 'working', 'steps'));
        self::assertIsArray($doc->get('years', 1, 'what_changed', 'working', 'steps'));
    }

    public function testOwnershipGivesItsDepreciationWorkingWithTheValuationDate(): void
    {
        $doc = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/ownership?include=working'));

        self::assertStringContainsString(
            'price paid £15,000.00 − latest value £9,800.00 (1 Mar 2026) = £5,200.00',
            $doc->string('working', 'depreciation', 'steps', 0),
        );
        self::assertSame('2026-03-01', $doc->get('working', 'depreciation', 'inputs', 1, 'as_of', 'date'));
        self::assertSame('valuation', $doc->get('working', 'depreciation', 'inputs', 1, 'as_of', 'kind'));
        self::assertSame('5200.000', $doc->get('working', 'depreciation', 'result_value'));
        self::assertNull(ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/ownership'))->get('working'));
    }

    public function testFuelEconomyGivesTheStretchItIsMeasuredOver(): void
    {
        $doc = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/fuel?include=working'));

        $measured = 0;
        foreach ($doc->keys('items') as $i) {
            if (!$doc->has('items', $i, 'economy', 'working')) {
                continue;
            }
            $measured++;
            self::assertStringContainsString(
                'between your full fill-ups of',
                $doc->string('items', $i, 'economy', 'working', 'steps', 0),
            );
        }
        self::assertGreaterThan(0, $measured, 'the fill-ups close measured stretches');
    }

    public function testFinanceGivesTheSettlementEquityAndInterestWorking(): void
    {
        $this->service($this->app, FinanceAgreementRepository::class)->insert($this->golf->id, new AgreementData(
            type: AgreementType::Hp,
            lender: 'Lender',
            agreementNumber: null,
            startedOn: self::date('2025-03-01'),
            firstPaymentOn: self::date('2025-04-01'),
            numberOfPayments: 36,
            regularPayment: '250',
            cashPrice: '9000',
            apr: '6.9',
        ), new DateTimeImmutable('2026-10-05T12:00:00Z'), $this->owner->id);

        $doc = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/finance?include=working'));

        self::assertSame(
            $doc->string('agreement', 'settlement', 'amount'),
            $doc->string('agreement', 'working', 'settlement', 'result_value') . '0',
            'the working ends at the settlement the figure shows',
        );
        self::assertSame('9800.000', $doc->get('agreement', 'equity', 'valuation'));
        self::assertNotNull($doc->get('agreement', 'working', 'equity'));
        self::assertNotNull($doc->get('agreement', 'working', 'interest_so_far'));
        $plain = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/finance'));
        self::assertNull($plain->get('agreement', 'working'));
    }

    public function testAnUnknownIncludeIsABadRequest(): void
    {
        $response = $this->api->get('/vehicles/' . $this->golf->id . '/ownership?include=working,cheese');

        self::assertSame(400, $response->getStatusCode());
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }
}
