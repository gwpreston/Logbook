<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use DateTimeImmutable;
use DI\Container;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\ReminderRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\Mailer\Transport\TransportInterface as MailTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fixtures for reminder and notification tests: an owner's vehicles,
 * documents and schedules, plus recording stand-ins for SMTP and HTTP.
 */
abstract class ReminderTestCase extends AppTestCase
{
    /** Every shipped channel configured (and APP_URL for absolute links). */
    protected const array CHANNELS = [
        'APP_URL' => 'https://garage.example',
        'MAIL_HOST' => 'smtp.test',
        'MAIL_FROM' => 'Logbook <logbook@garage.example>',
        'MAIL_TO' => 'owner@example.com',
        'NTFY_URL' => 'https://ntfy.test/garage',
        'NTFY_TOKEN' => 'tk_secret',
        'GOTIFY_URL' => 'https://gotify.test/',
        'GOTIFY_TOKEN' => 'AppToken1',
        'GOTIFY_PRIORITY' => '6',
        'WEBHOOK_URL' => 'https://hooks.test/logbook',
    ];

    /** No channel configured, whatever the environment says. */
    protected const array NO_CHANNELS = [
        'MAIL_HOST' => '',
        'NTFY_URL' => '',
        'GOTIFY_URL' => '',
        'GOTIFY_TOKEN' => '',
        'WEBHOOK_URL' => '',
    ];

    protected RecordingMailTransport $mail;
    protected RecordingHttpClient $http;

    /**
     * An app whose outbound email and HTTP are recorded, not sent.
     *
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    protected function createRecordingApp(array $env = self::CHANNELS): App
    {
        $app = $this->createApp($env + self::NO_CHANNELS);
        $this->mail = new RecordingMailTransport();
        $this->http = new RecordingHttpClient();

        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(MailTransport::class, $this->mail);
        $container->set(HttpClientInterface::class, $this->http->client);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function owner(App $app): User
    {
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);

        return $owner;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function vehicle(App $app, string $model = 'Golf'): Vehicle
    {
        return $this->service($app, VehicleService::class)
            ->create($this->owner($app), new VehicleData(VehicleType::Car, 'Volkswagen', $model, FuelType::Petrol));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function document(
        App $app,
        Vehicle $vehicle,
        ?string $expiry,
        ComplianceType $type = ComplianceType::Insurance,
        ?string $start = null,
        ?string $title = null,
    ): ComplianceDocument {
        return $this->service($app, ComplianceService::class)->create($vehicle, new ComplianceDocumentData(
            type: $type,
            title: $title,
            startOn: $start === null ? null : self::date($start),
            expiryOn: $expiry === null ? null : self::date($expiry),
        ));
    }

    /**
     * A schedule last done on $lastDoneOn, every $months months.
     *
     * @param App<ContainerInterface> $app
     */
    protected function schedule(
        App $app,
        Vehicle $vehicle,
        string $title,
        string $lastDoneOn,
        int $months = 12,
    ): MaintenanceSchedule {
        return $this->service($app, ScheduleService::class)->create($vehicle, new MaintenanceScheduleData(
            category: MaintenanceCategory::Service,
            title: $title,
            intervalMonths: $months,
            baselineDoneOn: self::date($lastDoneOn),
        ));
    }

    /**
     * Every stored reminder of the owner (archived vehicles included).
     *
     * @param App<ContainerInterface> $app
     * @return list<Reminder>
     */
    protected function reminders(App $app): array
    {
        return $this->service($app, ReminderRepository::class)->listForUser($this->owner($app)->id, false);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function onlyReminder(App $app): Reminder
    {
        $reminders = $this->reminders($app);
        self::assertCount(1, $reminders);

        return $reminders[0];
    }

    protected static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }
}
