<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\MotHistory;

use Logbook\Domain\Attention\AttentionKind;
use Logbook\Domain\MotHistory\RecallState;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MotTestRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Attention\AttentionList;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\Sample\SampleMotProvider;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;
use Logbook\Tests\Support\TestBrowser;

/**
 * MOT history in the sample data (`--with-sample-data`, spec.md §7.38,
 * #335): the sample provider is on, the sample vehicles' tests are stored
 * as a fetch would store them, their mileages sit inside the sample
 * mileage (no reading is flagged because of them), the Golf's tyre
 * advisory is a watched issue advised again, and a refresh changes
 * nothing.
 */
final class DemoMotHistoryTest extends AppTestCase
{
    private const string DEMO_PASSWORD = 'a-sample-demo-password-41';

    public function testTheSampleVehiclesHaveTheirMotHistory(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-10-06T10:00:00Z');
        $this->resetDatabase($app);
        putenv('DEMO_PASSWORD=' . self::DEMO_PASSWORD);
        try {
            Migrator::run('seed:run', ['--seed' => ['DemoDataSeeder']]);
        } finally {
            putenv('DEMO_PASSWORD');
        }
        $demo = $this->service($app, UserRepository::class)->findByUsername('demo');
        self::assertInstanceOf(User::class, $demo);
        $vehicles = [];
        foreach ($this->service($app, VehicleService::class)->listFleet($demo, true) as $vehicle) {
            $vehicles[(string) $vehicle->data->registration] = $vehicle;
        }

        self::assertSame(SampleMotProvider::CODE, $this->service($app, MotHistoryConfig::class)->provider()?->code());
        $tests = $this->service($app, MotTestRepository::class);
        self::assertCount(6, $tests->listForVehicle($this->vehicle($vehicles, 'LB19 KTR')->id));
        self::assertSame(RecallState::Yes, $tests->state($this->vehicle($vehicles, 'LB19 KTR')->id)->recall);
        self::assertSame('2027-02-09', $tests->state($this->vehicle($vehicles, 'EV23 KIA')->id)->firstDueOn?->format('Y-m-d'));

        $kinds = [];
        foreach ($this->service($app, AttentionList::class)->forVehicles($demo, array_values($vehicles))->items as $item) {
            if ($item->kind === AttentionKind::Reading) {
                self::assertNotSame('mot', $item->reading?->source->value, 'an MOT mileage clashes with the sample data');
                self::assertNull($item->motPair, 'an MOT mileage clashes with the sample data');
            }
            $kinds[] = $item->kind;
        }
        self::assertContains(AttentionKind::MotRecall, $kinds);

        $tyre = array_values(array_filter(
            $tests->listForVehicle($this->vehicle($vehicles, 'LB19 KTR')->id)[0]->defects,
            static fn ($defect): bool => str_starts_with($defect->text, 'Nearside Front Tyre'),
        ))[0] ?? null;
        self::assertNotNull($tyre?->issueId, 'advised again: linked to the watched issue');

        // A refresh through the sample provider finds what is stored.
        $browser = new TestBrowser($app);
        $browser->get('/login');
        $signIn = $browser->post('/login', ['username' => 'demo', 'password' => self::DEMO_PASSWORD]);
        self::assertSame(303, $signIn->getStatusCode());
        $golf = $this->vehicle($vehicles, 'LB19 KTR');
        $browser->post('/vehicles/' . $golf->id . '/mot-history/fetch', []);
        self::assertCount(6, $tests->listForVehicle($golf->id));
        $page = (string) $browser->get('/vehicles/' . $golf->id . '/mot-history')->getBody();
        self::assertStringContainsString('No new tests, 6 brought up to date.', $page);
    }

    /**
     * @param array<string, Vehicle> $vehicles
     */
    private function vehicle(array $vehicles, string $registration): Vehicle
    {
        self::assertArrayHasKey($registration, $vehicles);

        return $vehicles[$registration];
    }
}
