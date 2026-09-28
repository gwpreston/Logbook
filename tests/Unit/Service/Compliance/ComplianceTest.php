<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Compliance;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Service\Compliance\ComplianceDocumentForm;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Compliance\DocumentStatus;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Validation\ValidationErrors;
use PHPUnit\Framework\TestCase;

final class ComplianceTest extends TestCase
{
    private const array POLICY = [
        'type' => 'insurance',
        'title' => '',
        'provider' => 'Acme Insurance',
        'reference' => 'POL-123',
        'start_on' => '2026-03-01',
        'expiry_on' => '2027-02-28',
        'cost' => '',
        'notes' => '',
    ];

    public function testTheOdometerIsInTheOwnersUnitAndNeedsAStartDate(): void
    {
        $data = ComplianceDocumentForm::parse(['type' => 'inspection', 'odometer' => '30000'] + self::POLICY, self::uk());
        self::assertInstanceOf(ComplianceDocumentData::class, $data);
        self::assertSame('48280.320', $data->odometerKm, '30,000 mi in km');
        self::assertSame('30000', ComplianceDocumentForm::values(self::document(1, $data), self::uk())['odometer']);

        $blank = ComplianceDocumentForm::parse(['odometer' => ''] + self::POLICY, self::uk());
        self::assertInstanceOf(ComplianceDocumentData::class, $blank);
        self::assertNull($blank->odometerKm);

        $undated = ComplianceDocumentForm::parse(['odometer' => '30000', 'start_on' => ''] + self::POLICY, self::uk());
        self::assertInstanceOf(ValidationErrors::class, $undated);
        self::assertSame('compliance.odometer_needs_start', $undated->all()['odometer']['key'] ?? null);

        self::assertArrayNotHasKey('odometer', ComplianceDocumentForm::defaults(ComplianceType::Inspection), 'never renewed');
    }

    public function testParsesAndRoundTripsADocument(): void
    {
        $data = ComplianceDocumentForm::parse(['cost' => '412.5'] + self::POLICY, self::uk());

        self::assertInstanceOf(ComplianceDocumentData::class, $data);
        self::assertSame(ComplianceType::Insurance, $data->type);
        self::assertNull($data->title);
        self::assertSame('2027-02-28', $data->expiryOn?->format('Y-m-d'));
        self::assertSame('412.500', $data->cost);

        $values = ComplianceDocumentForm::values(self::document(1, $data), self::uk());
        self::assertSame('insurance', $values['type']);
        self::assertSame('2026-03-01', $values['start_on']);
        self::assertSame('2027-02-28', $values['expiry_on']);
        self::assertSame('412.5', $values['cost']);
        self::assertSame('POL-123', $values['reference']);

        // What the edit form shows parses back to the same document.
        self::assertEquals($data, ComplianceDocumentForm::parse($values, self::uk()));
    }

    public function testZeroOrBlankCostIsValid(): void
    {
        foreach (['', '0', '0.00'] as $cost) {
            $data = ComplianceDocumentForm::parse(['cost' => $cost] + self::POLICY, self::uk());
            self::assertInstanceOf(ComplianceDocumentData::class, $data, $cost);
            self::assertSame('0.000', $data->cost);
        }
    }

    public function testExpiryCannotPrecedeStart(): void
    {
        $errors = ComplianceDocumentForm::parse(['expiry_on' => '2026-02-01'] + self::POLICY, self::uk());

        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame('compliance.expiry_before_start', $errors->all()['expiry_on']['key']);

        $sameDay = ComplianceDocumentForm::parse(['expiry_on' => '2026-03-01'] + self::POLICY, self::uk());
        self::assertInstanceOf(ComplianceDocumentData::class, $sameDay, 'a one-day document is fine');
    }

    public function testOtherDocumentsNeedAName(): void
    {
        $errors = ComplianceDocumentForm::parse(['type' => 'other'] + self::POLICY, self::uk());
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertTrue($errors->has('title'));

        $named = ComplianceDocumentForm::parse(['type' => 'other', 'title' => 'Breakdown cover'] + self::POLICY, self::uk());
        self::assertInstanceOf(ComplianceDocumentData::class, $named);
    }

    public function testStatusOnADay(): void
    {
        $today = self::date('2026-09-27');
        $status = static fn (?string $start, ?string $expiry): DocumentState => DocumentState::evaluate(
            self::document(1, new ComplianceDocumentData(
                ComplianceType::Insurance,
                startOn: $start === null ? null : self::date($start),
                expiryOn: $expiry === null ? null : self::date($expiry),
            )),
            $today,
        );

        self::assertSame(DocumentStatus::Valid, $status('2026-03-01', '2027-02-28')->status);
        self::assertSame(DocumentStatus::Expiring, $status('2025-10-28', '2026-10-27')->status);
        self::assertSame(30, $status('2025-10-28', '2026-10-27')->daysLeft);
        self::assertSame(DocumentStatus::Expiring, $status(null, '2026-09-27')->status, 'valid through its last day');
        self::assertSame(DocumentStatus::Expired, $status(null, '2026-09-26')->status);
        self::assertSame(-1, $status(null, '2026-09-26')->daysLeft);
        self::assertSame(DocumentStatus::Upcoming, $status('2026-10-01', '2027-09-30')->status);
        self::assertSame(DocumentStatus::Open, $status('2020-01-01', null)->status);
    }

    public function testARenewalReplacesTheOldDocumentOfTheSameType(): void
    {
        $today = self::date('2026-09-27');
        $old = self::document(1, new ComplianceDocumentData(ComplianceType::Insurance, expiryOn: self::date('2026-10-05')));
        $renewal = self::document(2, new ComplianceDocumentData(
            ComplianceType::Insurance,
            startOn: self::date('2026-10-06'),
            expiryOn: self::date('2027-10-05'),
        ));
        $expiredMot = self::document(3, new ComplianceDocumentData(
            ComplianceType::Inspection,
            expiryOn: self::date('2026-09-01'),
        ));
        $other = static fn (int $id, string $name, string $expiry): ComplianceDocument => self::document(
            $id,
            new ComplianceDocumentData(ComplianceType::Other, $name, expiryOn: self::date($expiry)),
        );
        $otherA = $other(4, 'Toll tag', '2026-01-01');
        $otherB = $other(5, 'Permit', '2027-01-01');

        $states = DocumentState::evaluateAll([$old, $renewal, $expiredMot, $otherA, $otherB], $today);
        $byId = [];
        foreach ($states as $state) {
            $byId[$state->document->id] = $state->status;
        }

        self::assertSame(DocumentStatus::Replaced, $byId[1], 'renewed, so nothing to do');
        self::assertSame(DocumentStatus::Upcoming, $byId[2]);
        self::assertSame(DocumentStatus::Expired, $byId[3], 'the only inspection, and it has lapsed');
        self::assertSame(DocumentStatus::Expired, $byId[4], '"other" documents never replace each other');
        self::assertSame(DocumentStatus::Valid, $byId[5]);

        $order = array_map(static fn (DocumentState $s): int => $s->document->id, $states);
        self::assertSame([4, 3, 5, 2, 1], $order, 'most urgent first, then by expiry');
    }

    private static function document(int $id, ComplianceDocumentData $data): ComplianceDocument
    {
        return new ComplianceDocument($id, 1, $data, new DateTimeImmutable(), new DateTimeImmutable());
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }

    private static function uk(): DisplayPreferences
    {
        $preset = UnitPreset::Uk;

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
