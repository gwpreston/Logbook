<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Part;

use DateTimeImmutable;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartCondition;
use Logbook\Domain\Part\PartOrigin;
use Logbook\Domain\Part\PartData;
use Logbook\Domain\Part\PartFitting;
use Logbook\Domain\Part\PartStatus;
use Logbook\Domain\Part\RemovalOutcome;
use Logbook\Domain\Part\WarrantyStart;
use Logbook\Service\Part\PartChangeRefused;
use Logbook\Service\Part\PartDistance;
use Logbook\Service\Part\PartTimeline;
use Logbook\Service\Part\PartWarranty;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * A part's fittings read and checked (spec.md §7.39): its status, the
 * refusals, its distance across spells and its warranty.
 */
final class PartTimelineTest extends TestCase
{
    public function testTheStatusComesFromTheLastFitting(): void
    {
        self::assertSame(PartStatus::Stored, PartTimeline::status([]), 'a spare bought ahead');
        self::assertSame(PartStatus::Fitted, PartTimeline::status([self::fitting(1, '2026-01-01')]));
        self::assertSame(PartStatus::Stored, PartTimeline::status([self::fitting(1, '2026-01-01', '2026-02-01')]));
        foreach ([RemovalOutcome::Sold, RemovalOutcome::Returned, RemovalOutcome::Scrapped, RemovalOutcome::Other] as $outcome) {
            $gone = self::fitting(1, '2026-01-01', '2026-02-01', outcome: $outcome);
            self::assertSame(PartStatus::Gone, PartTimeline::status([$gone]));
        }
    }

    public function testFittingsMayMeetButNeverOverlap(): void
    {
        $part = self::part();
        PartTimeline::check($part, [
            self::fitting(1, '2026-01-01', '2026-03-01'),
            self::fitting(2, '2026-03-01'),
        ]);

        $this->expectRefusal('part.refused.overlap', fn () => PartTimeline::check($part, [
            self::fitting(1, '2026-01-01', '2026-03-02'),
            self::fitting(2, '2026-03-01'),
        ]));
        $this->expectRefusal('part.refused.overlap', fn () => PartTimeline::check($part, [
            self::fitting(1, '2026-01-01'),
            self::fitting(2, '2026-03-01'),
        ]));
    }

    public function testARemovalBeforeItsFittingAndAGonePartFittedAgainAreRefused(): void
    {
        $part = self::part();
        $this->expectRefusal('part.refused.removed_before_fitted', fn () => PartTimeline::check($part, [
            self::fitting(1, '2026-03-01', '2026-02-01'),
        ]));
        $this->expectRefusal('part.refused.gone_before', fn () => PartTimeline::check($part, [
            self::fitting(1, '2026-01-01', '2026-02-01', outcome: RemovalOutcome::Sold),
            self::fitting(2, '2026-03-01'),
        ]));
    }

    public function testDistanceAddsEachSpellAndAnOpenOneRunsToTheLatestReading(): void
    {
        $today = self::day('2026-10-14');
        $distance = PartDistance::of([
            self::fitting(1, '2026-01-01', '2026-03-01', fittedKm: '10000.000', removedKm: '12500.000'),
            self::fitting(2, '2026-06-01', fittedKm: '14000.000'),
        ], '15000.000', $today);
        self::assertSame('3500.000', $distance->km);
        self::assertFalse($distance->atLeast);
        self::assertFalse($distance->isFlagged());

        $partial = PartDistance::of([
            self::fitting(1, '2021-03-14', '2026-03-01', removedKm: '12500.000'),
            self::fitting(2, '2026-06-01', fittedKm: '14000.000'),
        ], '15000.000', $today);
        self::assertSame('1000.000', $partial->km);
        self::assertTrue($partial->atLeast, 'the first spell has no fitted mileage');

        self::assertNull(PartDistance::of([self::fitting(1, '2026-01-01')], null, $today)->km);

        $backwards = PartDistance::of([
            self::fitting(1, '2026-01-01', '2026-03-01', fittedKm: '12000.000', removedKm: '11000.000'),
        ], null, $today);
        self::assertSame('0.000', $backwards->km);
        self::assertTrue($backwards->isFlagged());
        self::assertSame('2026-01-01', $backwards->backwardsFrom?->format('Y-m-d'));
    }

    public function testAWarrantyRunsFromThePurchaseOrTheFittingAndADistanceFromTheFirstFitting(): void
    {
        $fittings = [self::fitting(1, '2025-11-20', fittedKm: '60000.000')];
        $fromFitting = self::part(new PartData(
            'Clutch kit',
            boughtOn: self::day('2025-10-01'),
            warrantyMonths: 24,
            warrantyKm: '40000.000',
            warrantyFrom: WarrantyStart::Fitting,
        ));
        $warranty = PartWarranty::of($fromFitting, $fittings, '70000.000', self::day('2026-10-14'));
        self::assertNotNull($warranty);
        self::assertSame('2027-11-20', $warranty->until?->format('Y-m-d'));
        self::assertSame('100000.000', $warranty->untilKm);
        self::assertTrue($warranty->active);

        $fromPurchase = self::part(new PartData(
            'Clutch kit',
            boughtOn: self::day('2024-10-01'),
            warrantyMonths: 24,
            warrantyFrom: WarrantyStart::Purchase,
        ));
        $ended = PartWarranty::of($fromPurchase, $fittings, null, self::day('2026-10-14'));
        self::assertNotNull($ended);
        self::assertFalse($ended->active);
        self::assertSame('2026-10-01', $ended->endedOn?->format('Y-m-d'));

        $byDistance = PartWarranty::of($fromFitting, $fittings, '100500.000', self::day('2026-10-14'));
        self::assertFalse($byDistance?->active, 'past the distance, though within the months');

        self::assertNull(PartWarranty::of(self::part(), $fittings, null, self::day('2026-10-14')), 'no warranty');

        $monthEnd = self::part(new PartData(
            'Battery',
            boughtOn: self::day('2026-01-31'),
            warrantyMonths: 1,
            warrantyFrom: WarrantyStart::Purchase,
        ));
        $until = PartWarranty::of($monthEnd, [], null, self::day('2026-02-01'))?->until;
        self::assertSame('2026-02-28', $until?->format('Y-m-d'), 'clamped, not 3 March');

        $unmeasured = PartWarranty::of($fromFitting, [self::fitting(1, '2025-11-20')], '70000.000', self::day('2026-10-14'));
        self::assertTrue($unmeasured?->active, 'no fitted mileage: the distance limit is unknown, not passed');
        self::assertNull($unmeasured->untilKm);
    }

    /**
     * SQLite never checks a column's length; PostgreSQL and MySQL refuse a
     * longer value, so every stored code is held to its column here.
     */
    public function testEveryStoredCodeFitsItsColumn(): void
    {
        $limits = [
            [OdometerSource::class, 16],
            [AttachmentOwner::class, 16],
            [ModificationCategory::class, 16],
            [PartCondition::class, 16],
            [PartOrigin::class, 8],
            [PartStatus::class, 8],
            [RemovalOutcome::class, 8],
            [WarrantyStart::class, 8],
        ];
        foreach ($limits as [$enum, $limit]) {
            foreach ($enum::cases() as $case) {
                self::assertLessThanOrEqual($limit, strlen($case->value), $enum . '::' . $case->name);
            }
        }
    }

    private function expectRefusal(string $key, callable $check): void
    {
        try {
            $check();
            self::fail('expected ' . $key);
        } catch (PartChangeRefused $refused) {
            self::assertSame($key, $refused->key);
        }
    }

    private static function part(?PartData $data = null): Part
    {
        $now = new DateTimeImmutable('2026-10-14 10:00:00 UTC');

        return new Part(7, 1, $data ?? new PartData('Standard exhaust'), PartStatus::Stored, $now, $now);
    }

    private static function fitting(
        int $id,
        string $fitted,
        ?string $removed = null,
        ?string $fittedKm = null,
        ?string $removedKm = null,
        ?RemovalOutcome $outcome = null,
    ): PartFitting {
        $now = new DateTimeImmutable('2026-10-14 10:00:00 UTC');

        return new PartFitting(
            $id,
            7,
            self::day($fitted),
            $fittedKm,
            null,
            null,
            false,
            $removed === null ? null : self::day($removed),
            $removedKm,
            null,
            null,
            false,
            $removed === null ? null : ($outcome ?? RemovalOutcome::Kept),
            null,
            null,
            $now,
            $now,
        );
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        self::assertNotNull($day);

        return $day;
    }
}
