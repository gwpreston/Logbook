<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Scan\Rules;

use DateTimeImmutable;
use Logbook\Domain\Ai\Scan\ScanKind;
use Logbook\Domain\Station\StationData;
use Logbook\Repository\StationRepository;
use Logbook\Service\Ai\Scan\Extraction;
use Logbook\Service\Ai\Scan\PrintedDate;
use Logbook\Service\Ai\Scan\PrintedNumber;
use Logbook\Service\Scan\Rules\RuleReader;
use Logbook\Service\Scan\Rules\RuleText;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\ScanFixture;
use Logbook\Tests\Support\ScanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The rules themselves (spec.md §7.27 *Reading without AI*, Phase 53.4):
 * every fixture's transcript is classified as its kind, and each value
 * read agrees with what the fixture says is printed; a fuel receipt's
 * volume, price and total are filled only as a set that adds up, and
 * never from a wrong label; an invoice's total, mileage and work lines
 * come from their own labels only.
 */
final class RuleReaderTest extends ScanTestCase
{
    /** What reading without AI must fill on each kind's fixtures, at least. */
    private const array REQUIRED = [
        'fuel_receipt' => ['date', 'volume', 'price_per_unit', 'total'],
        'service_invoice' => ['date', 'total'],
        'registration' => ['registration'],
        'inspection' => ['date'],
        'claim_letter' => ['date'],
        'repair_estimate' => ['date'],
        'other' => ['date'],
        'insurance' => [],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        foreach (ScanFixture::names() as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('fixtures')]
    public function testEachFixtureIsClassifiedAndReadRight(string $name): void
    {
        $fixture = ScanFixture::load($name);
        $this->scanApp([], $fixture->locale === 'de_DE' ? self::german() : null);
        $read = $this->read((string) file_get_contents(ScanFixture::DIRECTORY . '/' . $name . '.txt'));

        $kind = is_string($fixture->reply['kind'] ?? null) ? $fixture->reply['kind'] : '';
        self::assertSame($kind, $read->kind->value, 'classified');
        $expected = is_array($fixture->reply['fields'] ?? null) ? $fixture->reply['fields'] : [];
        foreach (self::REQUIRED[$kind] ?? [] as $field) {
            self::assertNotNull($read->value($field), $field . ' is read');
        }
        foreach ($read->fields as $field => $value) {
            $given = $expected[$field] ?? null;
            $want = is_array($given) ? ($given['value'] ?? null) : null;
            if (!is_string($want)) {
                continue;
            }
            self::assertTrue(
                self::same($want, $value['value'], $fixture->locale),
                $field . ': ' . $value['value'] . ' for ' . $want,
            );
        }
        self::assertSame([], $read->dropped, 'every value is on its line');
    }

    public function testAReceiptWithPointsVatAndAPumpNumberStillFindsItsSet(): void
    {
        $this->scanApp();
        $read = $this->read(implode("\n", [
            'CITY FUELS',
            '03/10/2026 18:01',
            'Pump 7',
            'Super Unleaded',
            'Litres 40.00',
            'Price/L £1.599',
            'Fuel £63.96',
            'Discount £2.00',
            'TOTAL £63.96',
            'VAT @ 20% £10.66',
            'Points earned 64',
            'Change £0.00',
        ]));

        self::assertSame(ScanKind::FuelReceipt, $read->kind);
        self::assertSame('40.00', $read->value('volume'));
        self::assertSame('1.599', $read->value('price_per_unit'));
        self::assertSame('63.96', $read->value('total'));
        self::assertSame('Super Unleaded', $read->value('grade'));
    }

    public function testNoAgreeingSetFillsTheTotalOnly(): void
    {
        $this->scanApp();
        // 42.18 × 1.429 is 60.28, not 65.00: an OCR misread somewhere.
        $read = $this->read(implode("\n", [
            'SHORELINE FUELS',
            '14/10/2026 08:42',
            'UNLEADED 42.18 L',
            '142.9p/L',
            'TOTAL GBP 65.00',
        ]));

        self::assertSame(ScanKind::FuelReceipt, $read->kind);
        self::assertNull($read->value('volume'), 'left for the owner');
        self::assertNull($read->value('price_per_unit'), 'left for the owner');
        self::assertSame('65.00', $read->value('total'));
    }

    public function testTwoSetsThatDisagreeFillNoVolumeOrPrice(): void
    {
        $this->scanApp();
        // Two pumps' lines on one receipt, each adding up to its own total.
        $read = $this->read(implode("\n", [
            'TWIN PUMPS',
            '14/10/2026',
            'UNLEADED 10.00 L @ 150.0p/L',
            'TOTAL £15.00',
            'DIESEL 20.00 L @ 160.0p/L',
            'TOTAL £32.00',
        ]));

        self::assertNull($read->value('volume'));
        self::assertNull($read->value('price_per_unit'));
        self::assertSame('32.00', $read->value('total'), 'the largest amount on a total line');
    }

    public function testAPriceWithoutAUnitMarkAddsUpInPence(): void
    {
        $this->scanApp();
        $read = $this->read(implode("\n", ['HILL GARAGE', '14/10/2026', 'Diesel 30.00 L', 'Price 155.9', 'Amount 46.77']));

        self::assertSame('155.9p', $read->value('price_per_unit'), 'read as pence, since that adds up');
        self::assertSame('30.00', $read->value('volume'));
        self::assertSame('46.77', $read->value('total'));
    }

    public function testAGermanReceiptReadsUnderDe(): void
    {
        $this->scanApp([], self::german());
        $read = $this->read(implode("\n", [
            'Tankstelle Am Ring',
            '12.10.2026 07:55',
            'Super E10',
            '45,32 Liter',
            '1,759 €/l',
            'Gesamtbetrag 79,72 €',
            'MwSt. 19 % 12,73 €',
        ]));

        self::assertSame(ScanKind::FuelReceipt, $read->kind);
        self::assertSame('45,32', $read->value('volume'));
        self::assertSame('1,759', $read->value('price_per_unit'));
        self::assertSame('79,72', $read->value('total'));
        self::assertSame('Super E10', $read->value('grade'));
    }

    public function testAToyotaInvoiceMentioningVscStaysAnInvoice(): void
    {
        $this->scanApp();
        $read = $this->read(implode("\n", [
            'Exeter Toyota, Marsh Barton',
            'Invoice 55120   Date: 02/10/2026',
            'Reg AB12 CDE   Mileage: 51,200',
            'Diagnostic: VSC warning light on, sensor replaced',
            'Labour £96.00   Parts £58.20',
            'VAT 20% £30.84',
            'Total due £185.04',
        ]));

        self::assertSame(ScanKind::ServiceInvoice, $read->kind, '#524: a marker is outscored');
        self::assertSame('185.04', $read->value('total'));
        self::assertSame('51,200', $read->value('odometer'));
        self::assertSame(['Diagnostic: VSC warning light on, sensor replaced'], $read->lines('work'));
    }

    public function testAnInvoicesTotalNeverComesFromASubtotalOrVat(): void
    {
        $this->scanApp();
        $read = $this->read(implode("\n", [
            'Moor Motors',
            'Invoice 7 Date 01/10/2026',
            'Brake pads',
            'Subtotal £500.00',
            'VAT £100.00',
            'Amount due £600.00',
            'Recommended: tyres in about 5,000 miles',
        ]));

        self::assertSame('600.00', $read->value('total'));
        self::assertNull($read->value('odometer'), '"5,000 miles" is not after a mileage word');
    }

    public function testALongStatementIsReadInBoundedTime(): void
    {
        // Merge review of 53.4: the set search was cubic (50 rows took 5.8 s, 200 would pass the time limit).
        $this->scanApp();
        $rows = [];
        for ($i = 0; $i < 200; $i++) {
            $volume = 30 + $i % 40 + 0.11;
            $rows[] = sprintf('%02d/03/2026 Shell Leeds Diesel %.2f L 1.459/L £%.2f', 1 + $i % 28, $volume, $volume * 1.459);
        }

        $started = microtime(true);
        $read = $this->read(implode("\n", $rows));

        self::assertLessThan(1.0, microtime(true) - $started);
        self::assertNull($read->value('volume'), 'too many candidates: no set is searched');
    }

    public function testATenderedAmountIsNeverTheTotal(): void
    {
        $this->scanApp();
        // 42.18 × 1.429 is 60.28, so no set agrees with 65.00 and the total stands alone.
        $read = $this->read(implode("\n", [
            'SHORELINE FUELS',
            '14/10/2026 08:42',
            'UNLEADED 42.18 L',
            '142.9p/L',
            'TOTAL GBP 65.00',
            'Amount tendered £100.00',
        ]));

        self::assertSame('65.00', $read->value('total'));
    }

    public function testAnInvoicesDateIsNotItsNextServiceDate(): void
    {
        $this->scanApp();
        $read = $this->read(implode("\n", [
            'Invoice 55',
            'Next service date 02/04/2027',
            'Date 02/10/2026',
            'Mileage 51,200',
            'Brakes',
            'Total due £185.04',
        ]));

        self::assertSame('02/10/2026', $read->value('date'));
        self::assertNull($read->value('vendor'), "the garage is left for the owner (#526)");
    }

    public function testTheStationIsOnlyOneOfTheInstallsStations(): void
    {
        // #526: a first row is as often an address or a product as a name.
        $this->scanApp();
        $receipt = static fn (string $first): string => implode("\n", [
            $first,
            '12 High Street, Leeds',
            '14/10/2026',
            'Diesel 30.00 L',
            '155.9p/L',
            'Total £46.77',
        ]);
        self::assertNull($this->read($receipt('SHELL PETROL STATION'))->value('vendor'), 'not one of ours: left for the owner');

        $stations = $this->service($this->app, StationRepository::class);
        $stations->insert(new StationData('BP'), $this->owner->id, new DateTimeImmutable(self::NOW));
        $stations->insert(new StationData('Shell Petrol Station'), $this->owner->id, new DateTimeImmutable(self::NOW));
        self::assertSame('BP', $this->read($receipt('BP'))->value('vendor'), 'a short name in capitals');
        self::assertSame('SHELL PETROL STATION', $this->read($receipt('SHELL PETROL STATION'))->value('vendor'));
        self::assertNull($this->read($receipt('Pump 4'))->value('vendor'));
    }

    public function testALongTextOfDateLikeWordsIsReadInBoundedTime(): void
    {
        // Merge review of 53.4: month names were rebuilt for every date-like candidate (3.6 s here).
        $this->scanApp();
        $text = mb_substr(str_repeat('12 abc 34 ', 2000), 0, 20000);

        $started = microtime(true);
        $this->read($text);

        self::assertLessThan(0.5, microtime(true) - $started);
    }

    public function testAnInvoiceDateBesideANextServiceDateIsRead(): void
    {
        $this->scanApp();
        $read = $this->read(implode("\n", [
            'Garage Ltd',
            'Invoice 55',
            'Next service due 01/09/2026 Invoice date 01/03/2025',
            'Total £120.00',
        ]));

        self::assertSame('01/03/2025', $read->value('date'));
    }

    public function testALoneTotalIsStrict(): void
    {
        // #526: never a number with a unit or a price, never after a saving or tendered word.
        $this->scanApp();
        $read = $this->read(implode("\n", ['BP', '01/03/2025', 'Fuel total 42.18 L 107.2p/L £45.99', 'Pump 4']));
        self::assertSame('45.99', $read->value('total'));

        $read = $this->read(implode("\n", ['Aral', '01.03.2025', 'Total 42,18 L 1,859 EUR/L 78,51 EUR']));
        self::assertSame('78,51', $read->value('total'));

        $saving = ['ESSO', '01/03/2025', 'Unleaded 42.18 L', '107.3 p/L', 'TOTAL £45.26 (saving £2.00)'];
        $read = $this->read(implode("\n", $saving));
        self::assertSame('45.26', $read->value('total'), 'the saving after it is never the total, nor hides it');

        $read = $this->read(implode("\n", ['ESSO', '01/03/2025', 'Amount £45.26']));
        self::assertNull($read->value('total'), 'a total word that is not the total itself, with no set agreeing');
    }

    public function testAShopBasketDoesNotHideTheFuelSet(): void
    {
        $this->scanApp();
        $rows = ['ESSO', '01/03/2025 08:42', 'Unleaded 42.18 L @ 107.2 p/L', 'TOTAL £45.22'];
        for ($i = 1; $i <= 13; $i++) {
            $rows[] = sprintf('Item %d £%d.49', $i, $i);
        }
        $read = $this->read(implode("\n", $rows));

        self::assertSame('42.18', $read->value('volume'));
        self::assertSame('107.2p', $read->value('price_per_unit'));
        self::assertSame('45.22', $read->value('total'));
    }

    public function testAV5csReferenceIsInNoKindsReading(): void
    {
        // Merge review of 53.4: the other kinds' readings kept the reference in their evidence.
        $this->scanApp();
        $read = $this->read(implode("\n", [
            'V5C Registration Certificate',
            'Registration AB12 CDE',
            'Issued 01/02/2026 1234      5678 901',
        ]));

        self::assertSame(ScanKind::Registration, $read->kind);
        $stored = (string) json_encode($read->toArray());
        self::assertDoesNotMatchRegularExpression('/1234\s*5678\s*901/', $stored);
        self::assertNotSame([], $read->alternatives, 'the other kinds are still read');
    }

    public function testReadItAsKeepsEachKindsOwnValues(): void
    {
        $this->scanApp();
        $read = $this->read((string) file_get_contents(ScanFixture::DIRECTORY . '/06-fuel-receipt-photo.txt'));
        $stored = Extraction::fromStored($read->toArray());
        self::assertNotNull($stored);

        self::assertNotNull($stored->readBy);
        self::assertTrue($stored->readBy->rules);
        self::assertSame('42.18', $stored->value('volume'));
        $other = $stored->as(ScanKind::Other);
        self::assertNull($other->value('volume'), 'other kinds: the date only');
        self::assertSame('14/10/2026', $other->value('date'));
        self::assertSame('42.18', $other->as(ScanKind::FuelReceipt)->value('volume'), 'and back again');
    }

    private function read(string $text): Extraction
    {
        return $this->service($this->app, RuleReader::class)->read($this->owner, explode("\n", $text), $text, null);
    }

    private static function same(string $want, string $got, string $locale): bool
    {
        $currency = RuleText::currency($want);
        if ($currency !== null && RuleText::currency($got) === $currency && PrintedNumber::read($want, $locale) === null) {
            return true;
        }
        if (mb_strtolower($want) === mb_strtolower($got) || str_contains(mb_strtolower($got), mb_strtolower($want))) {
            return true;
        }
        $wantDate = PrintedDate::read($want, $locale);
        $gotDate = PrintedDate::read($got, $locale);
        if ($wantDate !== null && $gotDate !== null) {
            return $wantDate->date == $gotDate->date;
        }
        $wantNumber = PrintedNumber::read($want, $locale);

        return $wantNumber !== null && $wantNumber === PrintedNumber::read($got, $locale);
    }

    private static function german(): DisplayPreferences
    {
        $preset = UnitPreset::Metric;

        return new DisplayPreferences(
            'de_DE',
            'Europe/Berlin',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'EUR',
        );
    }
}
