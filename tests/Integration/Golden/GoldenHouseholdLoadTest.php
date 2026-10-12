<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Golden;

use Logbook\Domain\Attachment\Attachment;
use Logbook\Repository\AttachmentRepository;
use Logbook\Support\Storage\FileStorage;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Golden\ExpectedSkeleton;
use Logbook\Tests\Support\Golden\GoldenHousehold;
use Logbook\Tests\Support\Golden\InputBuilder;

/**
 * The golden household (Phase 45.1) enters through the services on every
 * engine: each input row is stored once, the derived rows the app makes
 * (a fill-up's reading, the settlement event) are there, and the sold car
 * is archived with its sale.
 */
final class GoldenHouseholdLoadTest extends AppTestCase
{
    public function testEveryInputRowIsEnteredThroughTheServices(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, InputBuilder::TODAY_UTC);
        $this->resetDatabase($app);
        $household = GoldenHousehold::load($app->getContainer(), self::PASSWORD);

        $rows = static fn (string $name): int => count(ExpectedSkeleton::read(GoldenHousehold::INPUTS . '/' . $name . '.csv'));
        $connection = $this->connection($app);
        $count = static function (string $table) use ($connection): int {
            $value = $connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
            self::assertIsNumeric($value);

            return (int) $value;
        };
        self::assertSame($rows('users'), $count('users'));
        self::assertSame($rows('vehicles'), $count('vehicles'));
        self::assertSame($rows('shares'), $count('vehicle_shares'));
        self::assertSame($rows('fillups'), $count('fuel_entries'));
        self::assertSame($rows('maintenance'), $count('maintenance_entries'));
        self::assertSame($rows('documents'), $count('compliance_documents'));
        self::assertSame($rows('expenses'), $count('expense_entries'));
        self::assertSame($rows('valuations'), $count('vehicle_valuations'));
        self::assertSame($rows('schedules'), $count('maintenance_schedules'));
        self::assertSame($rows('incidents'), $count('incidents'));
        self::assertSame($rows('finance'), $count('finance_agreements'));
        self::assertSame($rows('finance_events'), $count('finance_payment_events'));
        self::assertSame($rows('rate_sets'), $count('mileage_rate_sets'));
        self::assertSame($rows('trips'), $count('trips'));
        // Every fill-up and maintenance entry with an odometer has its reading, plus the readings typed on their own.
        self::assertSame($rows('fillups') + $rows('maintenance') + $rows('readings'), $count('odometer_readings'));

        // The receipts, each on its entry, with the file's own content.
        $owners = ['fillups' => 'fuel', 'maintenance' => 'maintenance', 'documents' => 'compliance', 'expenses' => 'expense'];
        $storage = $this->service($app, FileStorage::class);
        $attachments = $this->service($app, AttachmentRepository::class);
        foreach (ExpectedSkeleton::read(GoldenHousehold::INPUTS . '/attachments.csv') as $row) {
            $found = array_values(array_filter(
                $attachments->listForVehicle($household->vehicle($row['vehicle'])->id),
                static fn (Attachment $a): bool => $a->filename === $row['file'],
            ));
            self::assertCount(1, $found, $row['file']);
            self::assertSame($owners[$row['kind']], $found[0]->ownerType->value, $row['file']);
            if (str_ends_with($row['file'], '.pdf')) {
                self::assertFileEquals(
                    GoldenHousehold::INPUTS . '/../uploads/' . $row['file'],
                    $storage->absolutePath($found[0]->storedPath),
                    'a PDF is stored as uploaded',
                );
            }
        }
        self::assertSame($rows('attachments'), $count('attachments'));

        $bySam = count(array_filter(
            ExpectedSkeleton::read(GoldenHousehold::INPUTS . '/fillups.csv'),
            static fn (array $row): bool => $row['by'] === 'sam',
        ));
        self::assertEquals(
            $bySam,
            $connection->fetchOne('SELECT COUNT(*) FROM fuel_entries WHERE created_by = ?', [$household->user('sam')->id]),
            "Sam's fill-ups are Sam's",
        );

        $focus = $household->vehicle('focus');
        self::assertTrue($focus->isArchived());
        self::assertSame('2026-08-19', $focus->data->saleDate?->format('Y-m-d'));
        self::assertSame(
            ['ev', 'golf'],
            array_keys(array_filter(
                $household->vehicles,
                fn ($vehicle): bool => $vehicle->userId === $household->user('alex')->id && !$vehicle->isArchived(),
            )),
        );
    }
}
