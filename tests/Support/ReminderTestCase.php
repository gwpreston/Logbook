<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use DateTimeImmutable;
use DI\Container;
use DateTimeZone;
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
use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\NotificationChannelRepository;
use Logbook\Repository\ReminderRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Mail\MailConfig;
use Logbook\Service\Mail\MailEncryption;
use Logbook\Service\Mail\NotificationSecrets;
use Logbook\Service\Mail\SmtpServer;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Net\HostResolver;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\Mailer\Transport\TransportInterface as MailTransport;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fixtures for reminder and notification tests: an owner's vehicles,
 * documents and schedules, plus recording stand-ins for SMTP and HTTP.
 */
abstract class ReminderTestCase extends AppTestCase
{
    /**
     * Every shipped channel configured (and APP_URL for absolute links). The
     * email server is described by the test-only `TEST_MAIL_*` keys: it is
     * saved as the `email.smtp` setting (spec.md §7.11), since the app reads
     * no `MAIL_*` variables any more. The `NTFY_*` and `GOTIFY_*` keys
     * describe the owner's own channels (Phase 36.2: the app no longer reads
     * them), saved as the upgrade would import them; `WEBHOOK_URL` is the
     * server's webhook, which the app still reads.
     */
    protected const array CHANNELS = [
        'APP_URL' => 'https://garage.example',
        'TEST_MAIL_HOST' => 'smtp.test',
        'TEST_MAIL_FROM' => 'Logbook <logbook@garage.example>',
        'TEST_MAIL_TO' => 'owner@example.com',
        'NTFY_URL' => 'https://ntfy.test/garage',
        'NTFY_TOKEN' => 'tk_secret',
        'GOTIFY_URL' => 'https://gotify.test/',
        'GOTIFY_TOKEN' => 'AppToken1',
        'GOTIFY_PRIORITY' => '6',
        'WEBHOOK_URL' => 'https://hooks.test/logbook',
    ];

    /** Seals the owner's channel tokens. */
    protected const string SESSION_SECRET = 'reminder-test-secret-0123456789abcdef0123456789';

    /** No channel configured, whatever the environment says. */
    protected const array NO_CHANNELS = [
        'SESSION_SECRET' => self::SESSION_SECRET,
        'TEST_MAIL_HOST' => '',
        'NTFY_URL' => '',
        'GOTIFY_URL' => '',
        'GOTIFY_TOKEN' => '',
        'WEBHOOK_URL' => '',
    ];

    protected RecordingMailTransport $mail;
    /** The email server the recording app has (null: email off); saved again after a reset. */
    protected ?SmtpServer $smtp = null;
    protected RecordingHttpClient $http;
    /** The test hosts resolve to public addresses (spec.md §7.11 *Where members' channels may send*). */
    protected FakeHostResolver $dns;
    /** @var array<string, string> the recording app's channel keys */
    private array $channelEnv = [];

    /**
     * An app whose outbound email and HTTP are recorded, not sent.
     *
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    protected function createRecordingApp(array $env = self::CHANNELS): App
    {
        $env += self::NO_CHANNELS;
        $this->channelEnv = $env;
        $app = $this->createApp($env);
        $this->smtp = $env['TEST_MAIL_HOST'] === '' ? null : new SmtpServer(
            host: $env['TEST_MAIL_HOST'],
            port: 587,
            encryption: MailEncryption::Tls,
            username: null,
            fromAddress: Address::create($env['TEST_MAIL_FROM'] ?? 'logbook@localhost')->getAddress(),
            fromName: Address::create($env['TEST_MAIL_FROM'] ?? 'logbook@localhost')->getName() ?: 'Logbook',
            adminRecipient: ($env['TEST_MAIL_TO'] ?? '') === '' ? null : $env['TEST_MAIL_TO'],
        );
        $this->saveSmtp($app);
        $this->mail = new RecordingMailTransport();
        $this->http = new RecordingHttpClient();

        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(MailTransport::class, $this->mail);
        $container->set(HttpClientInterface::class, $this->http->client);
        $this->dns = new FakeHostResolver();
        $this->dns->hosts += [
            'ntfy.test' => ['203.0.113.10'],
            'gotify.test' => ['203.0.113.11'],
            'hooks.test' => ['203.0.113.12'],
        ];
        $container->set(HostResolver::class, $this->dns);

        return $app;
    }

    /**
     * Signed in as the owner, who has the recording app's ntfy and Gotify as
     * their own channels.
     *
     * @param App<ContainerInterface> $app
     */
    protected function signedIn(App $app): TestBrowser
    {
        $browser = parent::signedIn($app);
        $owner = $this->owner($app);
        $env = $this->channelEnv;
        if (($env['NTFY_URL'] ?? '') !== '') {
            $this->giveChannel($app, $owner, 'ntfy', ['url' => $env['NTFY_URL']], ['token' => $env['NTFY_TOKEN'] ?? '']);
        }
        if (($env['GOTIFY_URL'] ?? '') !== '' && ($env['GOTIFY_TOKEN'] ?? '') !== '') {
            $this->giveChannel($app, $owner, 'gotify', [
                'url' => rtrim($env['GOTIFY_URL'], '/'),
                'priority' => (int) ($env['GOTIFY_PRIORITY'] ?? 5),
            ], ['token' => $env['GOTIFY_TOKEN']]);
        }

        return $browser;
    }

    /**
     * Save a personal channel for a user, as Account → Notifications would.
     *
     * @param App<ContainerInterface> $app
     * @param array<string, scalar> $settings
     * @param array<string, string> $secrets empty values are left out
     */
    protected function giveChannel(App $app, User $user, string $kind, array $settings, array $secrets = [], bool $enabled = true): void
    {
        $secrets = array_filter($secrets, static fn (string $v): bool => $v !== '');
        foreach ($secrets as $field => $value) {
            $this->service($app, NotificationSecrets::class)->store($user->id, $kind . '.' . $field, $value);
        }
        if ($secrets !== []) {
            $settings['_secrets'] = array_keys($secrets);
        }
        $this->service($app, NotificationChannelRepository::class)
            ->save($user->id, $kind, $settings, $enabled, new DateTimeImmutable());
    }

    /**
     * Empty the database, keeping the recording app's email server.
     *
     * @param App<ContainerInterface> $app
     */
    protected function resetDatabase(App $app): void
    {
        parent::resetDatabase($app);
        $this->saveSmtp($app);
    }

    /**
     * Save the test's email server as an admin would in Settings → Delivery.
     *
     * @param App<ContainerInterface> $app
     */
    protected function saveSmtp(App $app): void
    {
        if ($this->smtp !== null) {
            $this->service($app, SettingRepository::class)->save(MailConfig::SETTING, $this->smtp->toStored(null, ''));
        }
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
     * Make the owner one from before 2.1.0 who never saved the Notifications
     * card (no stored row, so no digest), for tests that count what is sent.
     *
     * @param App<ContainerInterface> $app
     */
    protected function ownerFromBefore21(App $app): void
    {
        $this->service($app, SettingRepository::class)->delete('notifications', SettingScope::User, $this->owner($app)->id);
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
        ), new DateTimeZone('Europe/London'));
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
        return $this->service($app, ReminderRepository::class)->listForVehicles(array_map(
            static fn (Vehicle $vehicle): int => $vehicle->id,
            $this->ownedVehicles($app, $this->owner($app)->id),
        ));
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
