<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Scenario;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Repository\FinanceAgreementRepository;

/**
 * Scenario 1: a car bought on a PCP, run for three years and sold (spec.md
 * §7.7, §7.32, §7.35). See tests/Fixtures/golden/pcp-purchase-sale.json for
 * the figures and how they were worked out.
 */
final class PcpPurchaseAndSaleTest extends ScenarioTestCase
{
    public function testThePageTheApiAndTheCsvAgreeWithTheGoldenFigures(): void
    {
        [$app, $browser, $api] = $this->start();
        $golf = $this->bought($app, '2023-03-01', '20000', '2026-03-01', '11000');
        $this->reading($app, $golf, '16093.44', '2023-03-01T12:00:00Z');
        $this->fillUp($app, $golf, '2023-06-01T08:00:00Z', '24140.16', '50', '600');
        $this->fillUp($app, $golf, '2024-06-01T08:00:00Z', '48280.32', '50', '700');
        $this->fillUp($app, $golf, '2025-06-01T08:00:00Z', '72420.48', '50', '800');
        $this->reading($app, $golf, '96560.64', '2026-03-01T12:00:00Z');
        $this->maintenance($app, $golf, '2024-09-01', 'Service', '450');
        $this->maintenance($app, $golf, '2025-09-01', 'Service', '350');
        $this->document($app, $golf, ComplianceType::Insurance, '2023-03-01', '2024-02-29', '732');
        $this->document($app, $golf, ComplianceType::Insurance, '2024-03-01', '2025-02-28', '730');
        $this->document($app, $golf, ComplianceType::Insurance, '2025-03-01', '2026-02-28', '730');
        $this->service($app, FinanceAgreementRepository::class)->insert($golf->id, new AgreementData(
            type: AgreementType::Pcp,
            lender: 'Lender',
            agreementNumber: null,
            startedOn: self::date('2023-03-01'),
            firstPaymentOn: self::date('2023-04-01'),
            numberOfPayments: 36,
            regularPayment: '250',
            finalPayment: '9000',
            cashPrice: '20000',
            customerDeposit: '2000',
            apr: '0',
            documentationFee: '99',
        ), new DateTimeImmutable('2023-03-01T00:00:00Z'), $this->owner($app)->id);

        $golden = self::golden('pcp-purchase-sale');
        $this->assertOwnershipAgrees($golden, $browser, $api, $golf);
        $this->assertTrueCostAgrees($golden, $browser, $api, $golf);
    }
}
