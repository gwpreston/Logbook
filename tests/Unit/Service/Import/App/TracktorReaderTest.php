<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Import\App;

use Logbook\Service\Import\App\Tracktor\TracktorExport;
use Logbook\Service\Import\App\Tracktor\TracktorReader;
use Logbook\Service\Import\App\Tracktor\TracktorRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading a Tracktor export (spec.md §7.13 *The Tracktor reader*), against
 * real exports of Tracktor 2.1.0 and 1.4.1 (tests/Fixtures/import/tracktor).
 */
final class TracktorReaderTest extends TestCase
{
    public function testTheMetricExportIsReadVehicleByVehicle(): void
    {
        $export = self::fixture('export-metric.json');

        self::assertFalse($export->legacy);
        self::assertSame(0, $export->orphans);
        self::assertSame(['notifications', 'notificationProviders'], $export->unread);
        self::assertSame(['kilometer', 'liter', 'liter', 'kilogram', 'INR'], [
            $export->config->distance,
            $export->config->volume,
            $export->config->lpg,
            $export->config->cng,
            $export->config->currency,
        ]);
        self::assertSame('2026-10-16', $export->exportedAt?->format('Y-m-d'));
        self::assertCount(2, $export->vehicles);

        [$car, $bike] = $export->vehicles;
        self::assertSame(['Maruti Suzuki', 'Swift', 2019, 'KA01AB1234', 'MA3EWDE1S00123456'], [
            $car->make,
            $car->model,
            $car->year,
            $car->plate,
            $car->vin,
        ]);
        self::assertSame('46210', $car->odometer);
        self::assertSame('#c0392b', $car->color);
        self::assertSame(['Variant' => 'VXi', 'Tyre size' => '185/65 R15'], $car->customFields);
        self::assertSame('1792132539713-swift.png', $car->image);
        self::assertSame('car', $car->vehicleType);
        self::assertFalse($car->isBike());
        self::assertTrue($bike->isBike());

        self::assertCount(8, $car->fills);
        $first = $car->fills[0];
        self::assertSame(['2026-01-04', '41020', '30.5', '102.86', '3137.23', true, false], [
            $first->date,
            $first->odometer,
            $first->amount,
            $first->rate,
            $first->cost,
            $first->full,
            $first->missedLast,
        ]);
        self::assertFalse($car->fills[2]->full, 'filled false is a partial fill');
        self::assertTrue($car->fills[4]->missedLast);
        self::assertNull($car->fills[5]->rate, 'two of the three amounts');
        self::assertNull($car->fills[6]->amount);
        self::assertSame(['29.876', '104.333'], [$car->fills[7]->amount, $car->fills[7]->rate], 'three decimals, read as text');
        self::assertSame('1792132539794-fuel-bill.pdf', $car->fills[1]->attachment);

        self::assertCount(3, $car->services);
        self::assertSame(['2026-01-15', '41300', 'Bluebird Motors', '4850.5'], [
            $car->services[0]->date,
            $car->services[0]->odometer,
            $car->services[0]->serviceCenter,
            $car->services[0]->cost,
        ]);
        self::assertSame('0', $car->services[1]->cost, 'a free service');

        self::assertSame(['insurance', 'emissions', 'other'], array_map(static fn ($d): string => $d->type, $car->documents));
        self::assertSame(['yearly', null], [$car->documents[0]->recurrence, $car->documents[0]->endDate]);
        self::assertTrue($car->documents[0]->recurs());
        self::assertSame('FASTag', $car->documents[2]->otherLabel);
        self::assertSame(['insurance', 'emissions', 'roadworthiness', 'other'], $export->documentTypes());

        self::assertCount(2, $car->reminders);
        self::assertSame(['maintenance', '2026-12-15', 7, 'yearly', false], [
            $car->reminders[0]->type,
            $car->reminders[0]->dueDate,
            $car->reminders[0]->leadDays(),
            $car->reminders[0]->recurrence,
            $car->reminders[0]->completed,
        ]);
        self::assertTrue($car->reminders[1]->completed);

        self::assertContains($car->id, $car->ids());
        self::assertCount(1 + 8 + 3 + 3 + 2, $car->ids());
        self::assertSame([
            '1792132539713-swift.png',
            '1792132539794-fuel-bill.pdf',
            '1792132540014-service-invoice.pdf',
            '1792132540114-policy.pdf',
        ], $export->fileNames());
    }

    public function testProviderCredentialsAreNeverRead(): void
    {
        $raw = (string) file_get_contents(self::path('export-metric.json'));
        self::assertStringContainsString('notificationProviders', $raw);

        $export = self::fixture('export-metric.json');

        self::assertStringNotContainsString('gotify', strtolower(serialize($export)));
        self::assertStringNotContainsString('"iv"', serialize($export));
    }

    public function testTheImperialExportHasAnEvAndABus(): void
    {
        $export = self::fixture('export-imperial.json');

        $config = $export->config;
        self::assertSame(['mile', 'gallon', 'GBP'], [$config->distance, $config->volume, $config->currency]);
        self::assertTrue($export->config->isGallons());
        self::assertTrue($export->config->isMiles());
        self::assertSame(['diesel', 'electric', 'petrol'], array_map(static fn ($v): string => $v->fuelType, $export->vehicles));
        $bus = $export->vehicles[2];
        self::assertTrue($bus->hasOddType());
        self::assertNull($bus->odometer);
        self::assertSame(['Nickname' => 'Bertha'], $bus->customFields);
        self::assertTrue($export->hasFuel('electric'));
        self::assertFalse($export->hasFuel('lpg'));
    }

    public function testATracktor1ExportReadsInsurancesAndPuccsAndNeverItsUsers(): void
    {
        $export = self::fixture('export-1.4.1.json');

        self::assertTrue($export->legacy);
        self::assertSame(['users', 'sessions'], $export->unread);
        self::assertFalse($export->hasReminders());
        $car = $export->vehicles[0];
        self::assertNull($car->vehicleType, '1.x has no vehicle type');
        self::assertSame('car', $car->isBike() ? 'bike' : 'car');
        self::assertSame(
            [['insurance', 'HUK-55512', 'HUK-Coburg', '2026-01-01', null, '412.5', 'insurances'],
             ['emissions', 'AU-2026-77', 'DEKRA Berlin', '2026-02-20', '2028-02-19', null, 'puccs']],
            array_map(
                static fn ($d): array => [$d->type, $d->number, $d->issuer, $d->startDate, $d->endDate, $d->cost, $d->list],
                $car->documents,
            ),
        );
    }

    public function testAPasswordProtectedExportIsRefused(): void
    {
        $contents = (string) file_get_contents(self::path('export-encrypted.json'));
        self::assertTrue(TracktorReader::detect($contents));

        $this->expectExceptionObject(new TracktorRefused('import_app.tracktor.encrypted'));
        TracktorReader::read('export-encrypted.json', $contents);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'not JSON' => ['{"version": "1.0", "data": ', 'import_app.tracktor.not_json'];
        yield 'not an export' => ['{"hello": 1}', 'import_app.tracktor.not_json'];
        yield 'another version' => [
            '{"version": "2.0", "encrypted": false, "data": {"vehicles": []}}',
            'import_app.tracktor.version',
        ];
        yield 'encrypted flag' => [
            '{"version": "1.0", "encrypted": true, "data": {"vehicles": []}}',
            'import_app.tracktor.encrypted',
        ];
        yield 'no vehicles' => [
            '{"version": "1.0", "encrypted": false, "data": {"fuelLogs": []}}',
            'import_app.tracktor.not_json',
        ];
        yield 'too deep' => [
            '{"version": "1.0", "data": ' . str_repeat('[', 70) . str_repeat(']', 70) . '}',
            'import_app.tracktor.too_deep',
        ];
    }

    #[DataProvider('refusals')]
    public function testFilesLogbookDoesNotReadAreRefused(string $contents, string $key): void
    {
        try {
            TracktorReader::read('x.json', $contents);
            self::fail('Expected a refusal.');
        } catch (TracktorRefused $e) {
            self::assertSame($key, $e->key);
        }
    }

    public function testMoreThanTheRowLimitIsRefused(): void
    {
        $logs = array_fill(0, TracktorReader::MAX_ROWS, ['id' => 'x', 'vehicleId' => 'v']);
        $contents = (string) json_encode(['version' => '1.0', 'encrypted' => false, 'data' => [
            'vehicles' => [['id' => 'v', 'make' => 'A', 'model' => 'B']],
            'fuelLogs' => $logs,
        ]]);

        try {
            TracktorReader::read('big.json', $contents);
            self::fail('Expected a refusal.');
        } catch (TracktorRefused $e) {
            self::assertSame(['import.error.too_many_rows', ['max' => TracktorReader::MAX_ROWS]], [$e->key, $e->params]);
        }
    }

    public function testDetectTellsTracktorFromOtherFiles(): void
    {
        self::assertTrue(TracktorReader::detect("\u{FEFF}" . '{"version":"1.0","data":{}}'));
        self::assertFalse(TracktorReader::detect('"## Vehicle"'));
        self::assertFalse(TracktorReader::detect('{"version": 1'));
        self::assertFalse(TracktorReader::detect('{"data": {}}'));
    }

    public function testRowsForAVehicleNotInTheExportAreCountedNotRead(): void
    {
        $export = TracktorReader::read('x.json', (string) json_encode(['version' => '1.0', 'data' => [
            'vehicles' => [['id' => 'v1', 'make' => 'A', 'model' => 'B', 'customFields' => '{not json']],
            'fuelLogs' => [
                ['id' => 'f1', 'vehicleId' => 'v2'],
                ['id' => 'f2', 'vehicleId' => 'v1', 'attachment' => '../../etc/passwd'],
            ],
            'configs' => ['unitOfDistance' => 'mile', 'currency' => 'eur'],
            'somethingNew' => [],
        ]]));

        self::assertSame(1, $export->orphans);
        self::assertSame(['somethingNew'], $export->unread);
        self::assertSame([], $export->vehicles[0]->customFields);
        self::assertSame('passwd', $export->vehicles[0]->fills[0]->attachment, 'only the base name is kept');
        self::assertSame(['mile', 'EUR'], [$export->config->distance, $export->config->currency]);
        self::assertNull($export->exportedAt);
    }

    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function numbers(): iterable
    {
        yield 'integer' => [41020, '41020'];
        yield 'float' => [3137.23, '3137.23'];
        yield 'whole float' => [41020.0, '41020'];
        yield 'three places' => [29.876, '29.876'];
        yield 'float32 noise kept as written' => [102.86000061035156, '102.86000061035156'];
        yield 'tiny' => [1.0E-7, '0.0000001'];
        yield 'numeric string' => [' 12.5 ', '12.5'];
        yield 'null' => [null, null];
        yield 'text' => ['abc', null];
        yield 'infinite' => [INF, null];
    }

    #[DataProvider('numbers')]
    public function testNumbersAreReadAsTheirDecimalText(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, TracktorReader::number($value));
    }

    /**
     * Tracktor stores the date picked as the browser's local midnight in UTC
     * (the London fixture's 30 March is "2026-03-29T23:00:00.000Z").
     *
     * @return iterable<string, array{string, string|null, string}>
     */
    public static function dates(): iterable
    {
        yield 'London in winter' => ['2026-01-04T00:00:00.000Z', null, '2026-01-04'];
        yield 'London in summer' => ['2026-03-29T23:00:00.000Z', null, '2026-03-30'];
        yield 'India' => ['2026-01-03T18:30:00.000Z', null, '2026-01-04'];
        yield 'New York' => ['2026-01-04T05:00:00.000Z', null, '2026-01-04'];
        yield 'Auckland in winter' => ['2026-07-03T12:00:00.000Z', null, '2026-07-04'];
        yield 'Auckland in summer, in the owner\'s zone' => ['2026-01-03T11:00:00.000Z', 'Pacific/Auckland', '2026-01-04'];
        yield 'Tonga, without its zone' => ['2026-07-03T11:00:00.000Z', null, '2026-07-03'];
        yield 'Tonga, in the owner\'s zone' => ['2026-07-03T11:00:00.000Z', 'Pacific/Tongatapu', '2026-07-04'];
        yield 'the owner\'s zone, not midnight there' => ['2026-01-03T18:30:00.000Z', 'Europe/London', '2026-01-04'];
        yield 'a date alone' => ['2026-02-01', 'Asia/Kolkata', '2026-02-01'];
    }

    #[DataProvider('dates')]
    public function testDatesAreTheCalendarDatePickedInTracktor(string $value, ?string $zone, string $expected): void
    {
        self::assertSame($expected, TracktorReader::date($value, $zone === null ? null : new \DateTimeZone($zone)));
    }

    public function testDatesThatAreNotRealAreNotRead(): void
    {
        self::assertNull(TracktorReader::date('2026-02-30T00:00:00.000Z'));
        self::assertNull(TracktorReader::date('2026-02-03Tnonsense'));
        self::assertNull(TracktorReader::date('01/02/2026'));
        self::assertNull(TracktorReader::date(null));
    }

    public function testTheLondonFixturesSummerFillUpsKeepTheirDay(): void
    {
        $export = self::fixture('export-metric.json');

        self::assertSame('2026-03-30', $export->vehicles[0]->fills[4]->date, 'typed as 30 March');
    }

    private static function path(string $name): string
    {
        return dirname(__DIR__, 5) . '/tests/Fixtures/import/tracktor/' . $name;
    }

    private static function fixture(string $name): TracktorExport
    {
        $contents = (string) file_get_contents(self::path($name));
        self::assertTrue(TracktorReader::detect($contents));

        return TracktorReader::read($name, $contents);
    }
}
