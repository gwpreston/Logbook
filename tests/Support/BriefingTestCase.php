<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\UserRepository;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Service\Vehicle\VehicleService;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\Mime\Email;

/**
 * Shared set-up for the monthly briefing's tests (Phase 43, spec.md §7.11
 * *The monthly briefing*): the digest runs on 1 Oct 2026 in London, so
 * "last month" is September 2026 and the averages look back over
 * September 2025 to August 2026.
 */
abstract class BriefingTestCase extends ReminderTestCase
{
    protected const string NOW = '2026-10-01T07:00:00Z';

    /** @var App<ContainerInterface> */
    protected App $app;
    protected TestBrowser $browser;
    protected MutableClock $clock;

    /**
     * @param array<string, string> $env
     */
    protected function start(array $env = self::CHANNELS): void
    {
        $this->app = $this->createRecordingApp($env);
        $this->clock = $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
        $this->saveChannels($this->browser, 'owner', 'pat@example.com');
    }

    /**
     * @param array<string, string|list<string>> $extra more form fields (the digest's Include boxes)
     */
    protected function saveChannels(TestBrowser $browser, string $username, string $email, array $extra = []): void
    {
        $user = $this->service($this->app, UserRepository::class)->findByUsername($username);
        self::assertNotNull($user);
        $this->withEmail($this->app, $user, $email);
        $browser->get('/settings/reminders');
        $response = $browser->post('/settings/reminders', $extra + [
            'schedule_days' => '30',
            'schedule_distance' => '621',
            'document_days' => '30',
            'manual_days' => '7',
            'channels' => ['email', 'webhook'],
            'digest' => '1',
        ]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
    }

    protected function car(string $model = 'Golf', ?string $currency = null): Vehicle
    {
        return $this->service($this->app, VehicleService::class)->create(
            $this->owner($this->app),
            new VehicleData(VehicleType::Car, 'Volkswagen', $model, FuelType::Petrol, currency: $currency),
        );
    }

    protected function reading(Vehicle $vehicle, string $km, string $date, string $time = '09:00:00'): void
    {
        $this->service($this->app, OdometerService::class)->create(
            $vehicle,
            new OdometerReadingData($km, new DateTimeImmutable($date . 'T' . $time . 'Z', new DateTimeZone('UTC'))),
        );
    }

    protected function spend(
        Vehicle $vehicle,
        string $date,
        string $amount,
        ExpenseCategory $category = ExpenseCategory::Parking,
    ): void {
        $this->service($this->app, ExpenseService::class)
            ->create($vehicle, new ExpenseEntryData(self::date($date), $category, $amount, null));
    }

    protected function insurance(Vehicle $vehicle, string $start, string $cost): void
    {
        $this->service($this->app, ComplianceService::class)->create($vehicle, new ComplianceDocumentData(
            type: ComplianceType::Insurance,
            startOn: self::date($start),
            expiryOn: self::date(date('Y-m-d', (int) strtotime($start . ' +1 year -1 day'))),
            cost: $cost,
        ), new DateTimeZone('Europe/London'));
    }

    protected function runTasks(): void
    {
        $this->service($this->app, ScheduledTasks::class)->run();
    }

    /**
     * @return list<Email> every digest email, to $to when given
     */
    protected function digestMails(?string $to = null): array
    {
        return array_values(array_filter(
            $this->mail->sent,
            static fn (Email $e): bool => ($to === null || $e->getTo()[0]->getAddress() === $to)
                && (str_starts_with((string) $e->getSubject(), 'Due in')
                    || str_contains((string) $e->getSubject(), 'monthly briefing')
                    || str_contains((string) $e->getSubject(), 'need')),
        ));
    }

    protected function digestText(string $to = 'pat@example.com'): string
    {
        $mails = $this->digestMails($to);
        self::assertCount(1, $mails, 'one digest to ' . $to);

        return (string) $mails[0]->getTextBody();
    }

    /**
     * The digest webhook's JSON for one user.
     *
     * @return array<string, mixed>
     */
    protected function digestJson(string $username = 'owner'): array
    {
        foreach ($this->http->to('https://hooks.test') as $request) {
            /** @var array<string, mixed> $json */
            $json = $request['json'];
            $user = $json['user'] ?? null;
            if (($json['event'] ?? null) === 'digest' && is_array($user) && ($user['username'] ?? null) === $username) {
                return $json;
            }
        }
        self::fail('No digest webhook for ' . $username);
    }

    /**
     * The first last-month row of a digest's JSON.
     *
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    protected static function row(array $json, int $index = 0): array
    {
        $rows = $json['last_month'] ?? null;
        self::assertIsArray($rows);
        $row = $rows[$index] ?? null;
        self::assertIsArray($row);

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * Readings on the 10th of each month in $months (Y-m), $km apart.
     *
     * @param list<string> $months
     */
    protected function monthlyReadings(Vehicle $vehicle, array $months, int $start, int $step): void
    {
        foreach ($months as $i => $month) {
            $this->reading($vehicle, (string) ($start + $i * $step), $month . '-10');
        }
    }

    protected static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
