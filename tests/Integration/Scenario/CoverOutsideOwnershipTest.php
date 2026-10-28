<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Scenario;

use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\ApiClient;

/**
 * Scenario 2: insurance cover that runs past the sale (spec.md §7.35 *Cover
 * outside ownership*, decided 2026-10-28, #564). Since bought counts the
 * whole document on its ledger date; no yearly period counts the days after
 * the sale, so the years add up to less by exactly those days' share.
 */
final class CoverOutsideOwnershipTest extends ScenarioTestCase
{
    public function testTheDaysAfterTheSaleCountInNoYear(): void
    {
        [$app, $browser, $api] = $this->start();
        $golf = $this->bought($app, '2024-07-01', '12000', '2025-07-01', '9000');
        $this->reading($app, $golf, '16093.44', '2024-07-01T12:00:00Z');
        $this->fillUp($app, $golf, '2024-11-01T08:00:00Z', '24140.16', '50', '500');
        $this->reading($app, $golf, '32186.88', '2025-07-01T12:00:00Z');
        $this->document($app, $golf, ComplianceType::Insurance, '2024-10-01', '2025-09-30', '730');

        $golden = self::golden('cover-past-the-sale');
        $this->assertOwnershipAgrees($golden, $browser, $api, $golf);
        $this->assertTrueCostAgrees($golden, $browser, $api, $golf);

        $doc = ApiClient::json($api->get('/vehicles/' . $golf->id . '/true-cost?period=since_bought'));
        $yearly = '0';
        foreach ($doc->keys('years') as $i) {
            $yearly = Decimal::add($yearly, $doc->string('years', $i, 'parts', 'compliance', 'amount'));
        }
        self::assertSame(
            $golden['outside_days_share'],
            Decimal::subtract($doc->string('true_cost', 'parts', 'compliance', 'amount'), $yearly),
            'since bought less the years is the cover after the sale',
        );
    }

    public function testAPolicyStartingBeforeThePurchaseCountsOnlyItsOwnedDaysInTheYears(): void
    {
        [$app, $browser, $api] = $this->start();
        $golf = $this->bought($app, '2024-07-01', '12000', '2025-07-01', '9000');
        $this->reading($app, $golf, '16093.44', '2024-07-01T12:00:00Z');
        $this->fillUp($app, $golf, '2024-11-01T08:00:00Z', '24140.16', '50', '500');
        $this->reading($app, $golf, '32186.88', '2025-07-01T12:00:00Z');
        $this->document($app, $golf, ComplianceType::Insurance, '2024-04-01', '2025-09-30', '1096');

        $golden = self::golden('cover-before-purchase');
        $this->assertOwnershipAgrees($golden, $browser, $api, $golf);
        $this->assertTrueCostAgrees($golden, $browser, $api, $golf);

        // Its ledger date is before the purchase, so since bought has none of it, yet the owned days count in the years.
        $doc = ApiClient::json($api->get('/vehicles/' . $golf->id . '/true-cost?period=since_bought'));
        $yearly = '0';
        foreach ($doc->keys('years') as $i) {
            $yearly = Decimal::add($yearly, $doc->string('years', $i, 'parts', 'compliance', 'amount'));
        }
        self::assertSame($golden['years_documents'], $yearly);
        self::assertSame('0.000', $doc->get('true_cost', 'parts', 'compliance', 'amount'));
    }
}
