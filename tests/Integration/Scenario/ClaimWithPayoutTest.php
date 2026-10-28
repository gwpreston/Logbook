<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Scenario;

use DateTimeZone;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Valuation\ValuationService;

/**
 * Scenario 3: a collision repaired for 1,400 and settled by the insurer for
 * 1,000 (spec.md §7.7 *Insurance payouts*): running costs are net of the
 * payout, in the page, the API and the CSV alike.
 */
final class ClaimWithPayoutTest extends ScenarioTestCase
{
    public function testRunningCostsAreNetOfThePayoutEverywhere(): void
    {
        [$app, $browser, $api] = $this->start();
        $golf = $this->bought($app, '2025-01-01', '10000');
        $this->reading($app, $golf, '16093.44', '2025-01-01T12:00:00Z');
        $this->fillUp($app, $golf, '2025-03-01T08:00:00Z', '19312.128', '50', '600');
        $this->service($app, IncidentService::class)->create($golf, new IncidentData(
            self::date('2025-06-15'),
            IncidentType::Collision,
            claim: new Claim(status: ClaimStatus::Settled, insurer: 'Aviva', excess: '0', payout: '1000'),
        ), null, new DateTimeZone('Europe/London'));
        $this->maintenance($app, $golf, '2025-06-20', 'Collision repair', '1400');
        $this->reading($app, $golf, '28968.192', '2026-06-01T12:00:00Z');
        $this->service($app, ValuationService::class)->create($golf, new VehicleValuationData(self::date('2026-06-01'), '8000'));
        $this->reading($app, $golf, '32186.88', '2026-09-01T09:00:00Z');

        $this->assertOwnershipAgrees(self::golden('claim-with-payout'), $browser, $api, $golf);
    }
}
