<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Scan;

use Logbook\Domain\Ai\Scan\ScanKind;
use Logbook\Domain\Ai\Scan\ScanTarget;
use Logbook\Domain\User\User;
use Logbook\Service\Ai\Scan\Extraction;
use Logbook\Service\Ai\Scan\Mapper;
use Logbook\Service\Ai\Scan\VehicleMatcher;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\ScanTestCase;

/**
 * Mapping a reading onto a form (spec.md §7.27 *Mapping*, *Checking
 * values*, *Vehicle*): each kind's fields, vehicle matching, dates read in
 * the user's order, and values left empty with the reason.
 */
final class ScanMappingTest extends ScanTestCase
{
    public function testAPlateWithSpacesOrDashesMatchesExactly(): void
    {
        $this->scanApp();
        $matcher = $this->service($this->app, VehicleMatcher::class);

        foreach (['AB12CDE', 'ab12 cde', 'AB12-CDE', ' AB 12 CDE '] as $plate) {
            $match = $matcher->match($this->owner, self::reading(['registration' => $plate]), null);
            self::assertSame($this->garage['Golf']->id, $match->vehicle?->id, $plate);
            self::assertFalse($match->isMismatch());
        }
    }

    public function testAPlateThatIsNotTheChosenVehiclesIsFlagged(): void
    {
        $this->scanApp();
        $matcher = $this->service($this->app, VehicleMatcher::class);

        $match = $matcher->match($this->owner, self::reading(['registration' => 'XY34 ZZZ']), $this->garage['Golf']->id);

        self::assertSame($this->garage['Golf']->id, $match->vehicle?->id, 'the chosen vehicle stays');
        self::assertTrue($match->isMismatch());
        self::assertSame($this->garage['BMW']->id, $match->documentVehicle?->id, 'and the one it names is offered');
    }

    public function testAnUnknownPlateWithSeveralVehiclesLeavesThePickToTheUser(): void
    {
        $this->scanApp();
        $matcher = $this->service($this->app, VehicleMatcher::class);

        self::assertNull($matcher->match($this->owner, self::reading(['registration' => 'ZZ00 ZZZ']), null)->vehicle);
        self::assertSame(
            $this->garage['Leaf']->id,
            $matcher->match($this->owner, self::reading(['make_model' => 'Nissan Leaf Tekna']), null)->vehicle?->id,
            'make and model when exactly one vehicle has them',
        );
    }

    public function testAnAmbiguousDateIsReadInTheUsersOrderAndMarked(): void
    {
        $this->scanApp();
        $uk = $this->map($this->owner, self::reading(['date' => '04/05/2026']));
        self::assertSame('2026-05-04', $uk->values['performed_on']);
        self::assertSame('Check the date: 4 May 2026 or 5 Apr 2026?', $uk->checks['performed_on']);

        $this->scanApp([], self::american());
        $us = $this->map($this->owner, self::reading(['date' => '04/05/2026']));
        self::assertSame('2026-04-05', $us->values['performed_on']);
        self::assertSame('Check the date: Apr 5, 2026 or May 4, 2026?', $us->checks['performed_on']);
    }

    public function testFutureDatesAndDatesBeforeTheVehicleAreLeftEmpty(): void
    {
        $this->scanApp();
        $future = $this->map($this->owner, self::reading(['date' => '20/12/2026']));
        self::assertArrayNotHasKey('performed_on', $future->values);
        self::assertSame('“20/12/2026” is in the future, so it was left empty.', $future->problems['performed_on']);

        $before = $this->map($this->owner, self::reading(['date' => '01/01/2017']));
        self::assertArrayNotHasKey('performed_on', $before->values);
        self::assertStringContainsString('before the vehicle’s first registration', $before->problems['performed_on']);

        $certificate = ['date' => '01/03/2026', 'expiry' => '28/02/2027', 'result' => 'pass'];
        $expiry = $this->map($this->owner, self::reading($certificate, ScanKind::Inspection));
        self::assertSame('2027-02-28', $expiry->values['expiry_on'], 'an expiry is in the future');
    }

    public function testAValueThatCannotBeReadSaysSo(): void
    {
        $this->scanApp();
        $form = $this->map($this->owner, self::reading(['total' => 'l2.5O', 'odometer' => 'unreadable']));

        self::assertArrayNotHasKey('cost', $form->values);
        self::assertSame('Couldn’t read “unreadable”.', $form->problems['odometer']);
    }

    public function testMilesOnTheDocumentAreConvertedForAMetricUser(): void
    {
        $this->scanApp([], self::metric());
        $form = $this->map($this->owner, self::reading(['odometer' => '10,000', 'odometer_unit' => 'miles']));

        self::assertSame('16093.44', $form->values['odometer']);
    }

    public function testAScheduleItCompletesIsSuggestedNeverChosen(): void
    {
        $this->scanApp();
        $golf = $this->garage['Golf'];
        $this->browser->post('/vehicles/' . $golf->id . '/maintenance/schedules/new', [
            'title' => 'Oil and filter',
            'category' => 'oil',
            'interval_months' => '12',
        ]);
        $form = $this->map($this->owner, new Extraction(ScanKind::ServiceInvoice, [], ['work' => ['Engine oil and filter']]));

        self::assertSame('oil', $form->values['category'] ?? null);
        self::assertArrayNotHasKey('schedule', $form->values);
        self::assertSame('This may complete “Oil and filter”. Choose it if it does.', $form->hints['schedule'] ?? null);
    }

    public function testTheReferenceNumberNeverSurvivesARegistrationDocument(): void
    {
        $reading = Extraction::fromObject([
            'kind' => 'registration',
            'fields' => [
                'registration' => ['value' => 'AB12 CDE', 'evidence' => 'Doc ref 1234 5678 901 · AB12 CDE'],
                'title' => ['value' => '12345678901', 'evidence' => '12345678901'],
            ],
        ]);

        $stored = (string) json_encode($reading->toArray());
        self::assertStringNotContainsString('12345678901', $stored);
        self::assertStringNotContainsString('1234 5678 901', $stored);
        self::assertSame('AB12 CDE', $reading->value('registration'));
    }

    public function testAValueWhoseEvidenceIsNotInTheTextIsDropped(): void
    {
        $text = "Invoice date 12/09/2026\nTotal £50.00";
        $reading = Extraction::fromObject([
            'kind' => 'service_invoice',
            'fields' => [
                'date' => ['value' => '12/09/2026', 'evidence' => 'invoice   DATE 12/09/2026'],
                'total' => ['value' => '£500.00', 'evidence' => 'Total £500.00'],
            ],
        ], $text);

        self::assertSame('12/09/2026', $reading->value('date'), 'case and spacing do not matter');
        self::assertNull($reading->value('total'), 'evidence the document does not contain');
    }

    public function testEachKindOpensItsForm(): void
    {
        self::assertSame(ScanTarget::Maintenance, ScanKind::ServiceInvoice->target());
        self::assertSame(ScanTarget::Fuel, ScanKind::FuelReceipt->target());
        self::assertSame(ScanTarget::Document, ScanKind::Inspection->target());
        self::assertSame(ScanTarget::Document, ScanKind::Insurance->target());
        self::assertSame(ScanTarget::Document, ScanKind::Other->target());
        self::assertSame(ScanTarget::Vehicle, ScanKind::Registration->target());
    }

    private function map(User $user, Extraction $reading): \Logbook\Service\Ai\Scan\ScanForm
    {
        return $this->service($this->app, Mapper::class)->map($user, $this->garage['Golf'], $reading);
    }

    /**
     * @param array<string, string> $fields
     */
    private static function reading(array $fields, ScanKind $kind = ScanKind::ServiceInvoice): Extraction
    {
        return new Extraction($kind, array_map(static fn (string $v): array => ['value' => $v, 'evidence' => $v], $fields));
    }

    private static function american(): DisplayPreferences
    {
        $preset = UnitPreset::Us;

        return new DisplayPreferences(
            'en_US',
            'America/New_York',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'USD',
        );
    }

    private static function metric(): DisplayPreferences
    {
        $preset = UnitPreset::Metric;

        return new DisplayPreferences(
            'en_GB',
            'Europe/London',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'GBP',
        );
    }
}
