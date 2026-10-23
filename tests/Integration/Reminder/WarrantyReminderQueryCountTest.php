<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Part\PartData;
use Logbook\Domain\Part\WarrantyStart;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Part\PartService;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Tests\Support\QueryCounter;
use Logbook\Tests\Support\ReminderTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * What warranty reminders cost the reminder job (spec.md §7.6, Phase 53.14,
 * K19): outside a page the sync reads each table once for every vehicle, so
 * vehicles with ticked parts add no queries each, and the first sync after
 * 200 parts are ticked adds them in two statements, not 200.
 */
final class WarrantyReminderQueryCountTest extends ReminderTestCase
{
    private const string NOW = '2026-09-30T10:00:00Z';

    public function testTwoHundredTickedPartsCostAFixedNumberOfQueries(): void
    {
        $app = $this->createApp(['FEATURES_PARTS' => 'true']);
        $counter = QueryCounter::install($app);
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $sync = fn (): int => $counter->during(
            fn () => $this->service($app, ReminderSync::class)->sync($this->owner($app)),
        );

        $vehicles = [];
        for ($i = 0; $i < 5; $i++) {
            $vehicles[] = $this->withTickedParts($app, 'Make' . $i, 1);
        }
        $sync();
        $five = $sync();

        for ($i = 5; $i < 10; $i++) {
            $vehicles[] = $this->withTickedParts($app, 'Make' . $i, 1);
        }
        $sync();
        $ten = $sync();
        self::assertSame($five, $ten, 'five more vehicles with ticked parts cost the job no more queries');

        foreach ($vehicles as $vehicle) {
            $this->tick($app, $vehicle, 19);
        }
        $first = $sync();
        self::assertSame(200, count(array_filter(
            $this->reminders($app),
            static fn ($r): bool => $r->source === ReminderSource::Part,
        )));
        self::assertLessThanOrEqual($ten + 2, $first, '190 new reminders in two statements');
        self::assertSame($ten, $sync(), '200 ticked parts cost what 10 did');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function withTickedParts(App $app, string $make, int $count): Vehicle
    {
        $vehicle = $this->vehicle($app, $make);
        $this->service($app, OdometerService::class)->create(
            $vehicle,
            new OdometerReadingData('10000', new DateTimeImmutable('2026-09-01T09:00:00Z', new DateTimeZone('UTC'))),
        );
        $this->tick($app, $vehicle, $count);

        return $vehicle;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function tick(App $app, Vehicle $vehicle, int $count): void
    {
        $parts = $this->service($app, PartService::class);
        for ($i = 0; $i < $count; $i++) {
            $parts->create($vehicle, new PartData(
                name: 'Part ' . $i . ' ' . bin2hex(random_bytes(3)),
                boughtOn: new DateTimeImmutable('2026-03-01'),
                warrantyMonths: 24,
                warrantyFrom: WarrantyStart::Purchase,
                warrantyReminder: true,
            ), new DateTimeZone('UTC'));
        }
    }
}
