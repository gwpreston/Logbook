<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Incident;

use DateTimeImmutable;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\Fault;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Incident\ClaimsRow;
use Logbook\Service\Incident\ClaimsStats;
use Logbook\Service\Incident\IncidentCosts;
use Logbook\Service\Incident\IncidentStats;
use Logbook\Service\Incident\IncidentView;
use Logbook\Support\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * The Incidents tab's strip and the claims history's tiles (Phase 33.3,
 * spec.md §7.29): only what the viewer may see is counted.
 */
final class IncidentStatsTest extends TestCase
{
    private static function incident(
        int $id,
        string $date,
        ClaimStatus $status,
        Fault $fault = Fault::Unknown,
        ?string $payout = null,
        ?string $excess = null,
    ): Incident {
        $now = new DateTimeImmutable('2026-09-29T10:00:00Z');
        $data = new IncidentData(
            new DateTimeImmutable($date),
            IncidentType::Collision,
            fault: $fault,
            claim: new Claim($status, excess: $excess, payout: $payout),
        );

        return new Incident($id, 1, $data, $now, $now, 1);
    }

    public function testNothingLoggedCountsNothing(): void
    {
        $stats = IncidentStats::of([]);

        self::assertSame(0, $stats->incidents);
        self::assertSame(0, $stats->claims);
        self::assertSame(0, $stats->atFault);
        self::assertSame([], $stats->insurerPaid);
        self::assertSame([], $stats->netCost);
    }

    public function testEveryDetailVisibleCountsClaimsAndFaults(): void
    {
        $at = self::incident(1, '2026-01-01', ClaimStatus::Settled, Fault::AtFault, '300');
        $not = self::incident(2, '2026-02-01', ClaimStatus::Open, Fault::NotAtFault);
        $none = self::incident(3, '2026-03-01', ClaimStatus::NotClaimed, Fault::AtFault);
        $stats = IncidentStats::of([
            IncidentView::of($at, true, true, IncidentCosts::of(1, [], '300', 'GBP')),
            IncidentView::of($not, true, true, IncidentCosts::of(2, [], null, 'GBP')),
            IncidentView::of($none, true, true, IncidentCosts::of(3, [], null, 'GBP')),
        ]);

        self::assertSame(3, $stats->incidents);
        self::assertSame(2, $stats->claims, 'never claimed is not a claim');
        self::assertSame(1, $stats->atFault, 'at-fault claims only');
        self::assertCount(1, $stats->insurerPaid);
        self::assertTrue($stats->insurerPaid[0]->equals(Money::of('300', 'GBP')));
    }

    public function testAHiddenFaultLeavesTheAtFaultCountOut(): void
    {
        $mine = self::incident(1, '2026-01-01', ClaimStatus::Open, Fault::AtFault);
        $theirs = self::incident(2, '2026-02-01', ClaimStatus::Settled, Fault::AtFault, '500');
        $stats = IncidentStats::of([
            IncidentView::of($mine, true, false),
            IncidentView::of($theirs, false, false),
        ]);

        self::assertSame(1, $stats->claims);
        self::assertNull($stats->atFault);
        self::assertSame([], $stats->insurerPaid);

        $hidden = IncidentStats::of([IncidentView::of($theirs, false, true, IncidentCosts::of(2, [], '500', 'GBP'))]);
        self::assertNull($hidden->claims, 'no claim is visible at all');
        self::assertSame([], $hidden->insurerPaid, 'the payout is a detail');
        self::assertCount(1, $hidden->netCost);
        self::assertTrue($hidden->netCost[0]->isZero(), 'the linked costs, never net of a hidden payout');
    }

    public function testTheClaimsTilesCountVisibleClaimsPerCurrency(): void
    {
        $gbp = self::vehicle(1);
        $eur = self::vehicle(2);
        $rows = [
            self::row($gbp, self::incident(1, '2026-06-02', ClaimStatus::Open, Fault::AtFault, null, '250')),
            self::row($gbp, self::incident(2, '2024-03-14', ClaimStatus::Settled, Fault::NotAtFault, '1000', '0')),
            self::row($eur, self::incident(3, '2023-05-02', ClaimStatus::Settled, Fault::AtFault, '800', '100'), 'EUR'),
            self::row($gbp, self::incident(4, '2025-01-01', ClaimStatus::Settled, Fault::AtFault, '999'), 'GBP', false),
            self::row($gbp, self::incident(5, '2025-02-01', ClaimStatus::NotClaimed, Fault::AtFault)),
        ];
        $stats = ClaimsStats::of($rows, new DateTimeImmutable('2026-09-29'));

        self::assertSame(3, $stats->claims, 'a hidden row and an unclaimed one are left out');
        self::assertSame(2, $stats->atFault);
        self::assertSame('2026-06-02', $stats->lastFault?->format('Y-m-d'));
        self::assertSame(0, $stats->yearsSinceFault);
        self::assertNotNull($stats->paid);
        self::assertSame(['GBP' => '1000.00', 'EUR' => '800.00'], self::sums($stats->paid));
        self::assertNotNull($stats->excess);
        self::assertSame(['GBP' => '250.00', 'EUR' => '100.00'], self::sums($stats->excess));

        $older = ClaimsStats::of([$rows[2]], new DateTimeImmutable('2026-09-29'));
        self::assertSame(3, $older->yearsSinceFault);
    }

    public function testWithoutAmountsOrFaultClaimsTheTilesSayNothing(): void
    {
        $row = new ClaimsRow(
            IncidentView::of(self::incident(1, '2026-01-01', ClaimStatus::Settled, Fault::NotAtFault, '400'), true, false),
            self::vehicle(1),
            null,
            'GBP',
        );
        $stats = ClaimsStats::of([$row], new DateTimeImmutable('2026-09-29'));

        self::assertSame(1, $stats->claims);
        self::assertSame(0, $stats->atFault);
        self::assertNull($stats->lastFault);
        self::assertNull($stats->yearsSinceFault);
        self::assertNull($stats->paid);
        self::assertNull($stats->excess);
    }

    /**
     * @param list<Money> $sums
     * @return array<string, string>
     */
    private static function sums(array $sums): array
    {
        $out = [];
        foreach ($sums as $sum) {
            $out[$sum->currency] = $sum->toDecimal(2);
        }

        return $out;
    }

    private static function row(Vehicle $vehicle, Incident $incident, string $currency = 'GBP', bool $details = true): ClaimsRow
    {
        return new ClaimsRow(IncidentView::of($incident, $details, true), $vehicle, null, $currency);
    }

    private static function vehicle(int $id): Vehicle
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new Vehicle(
            $id,
            1,
            new VehicleData(VehicleType::Car, 'Volkswagen', 'Golf', FuelType::Petrol),
            VehicleStatus::Active,
            null,
            null,
            null,
            $now,
            $now,
        );
    }
}
