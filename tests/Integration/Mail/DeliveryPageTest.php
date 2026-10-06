<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Mail;

use DateTimeImmutable;
use DI\Container;
use Logbook\Repository\NotificationSecretRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Mail\MailConfig;
use Logbook\Service\Mail\MailEncryption;
use Logbook\Service\Mail\TransportFactory;
use Logbook\Service\User\AccountMail;
use Logbook\Service\User\AccountMailer;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\RecordingTransportFactory;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\App;
use Symfony\Component\Mime\Email;

/**
 * Settings → Delivery → *Email server* (spec.md §7.11, Phase 36.1): admins
 * only; the saved settings are the only source (the old `MAIL_*` variables
 * are ignored and named); the password is sealed and never shown back, not
 * even after an error; *Send test email* uses the typed values unsaved and
 * reports the stage that failed, redacted; *Remove* turns email off; and
 * every email the app sends follows a change at once.
 */
final class DeliveryPageTest extends AppTestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const string PASSWORD_TYPED = 'smtp-hunter2-pass';

    private RecordingTransportFactory $transports;

    /** The form as an admin fills it in. */
    private const array FORM = [
        'intent' => 'save',
        'host' => 'smtp.example.com',
        'port' => '',
        'encryption' => 'tls',
        'username' => 'logbook@example.com',
        'password' => self::PASSWORD_TYPED,
        'from_address' => 'logbook@example.com',
        'from_name' => 'Our garage',
        'admin_recipient' => '',
    ];

    public function testOnlyAdminsReachThePage(): void
    {
        $app = $this->mailApp();
        $admin = $this->signedIn($app);
        self::assertSame(200, $admin->get('/settings/delivery')->getStatusCode());
        self::assertStringContainsString('/settings/delivery', self::body($admin->get('/settings')));

        $this->createMember($app);
        $member = $this->browserFor($app, 'partner');
        self::assertSame(404, $member->get('/settings/delivery')->getStatusCode());
        self::assertSame(404, $member->post('/settings/delivery', self::FORM)->getStatusCode());
        self::assertSame(404, $member->get('/settings/delivery/remove')->getStatusCode());
        self::assertStringNotContainsString('/settings/delivery', self::body($member->get('/settings')));
        self::assertNull($this->service($app, MailConfig::class)->effective(), 'nothing saved by a member');

        $signedOut = (new TestBrowser($app))->get('/settings/delivery');
        self::assertContains($signedOut->getStatusCode(), [302, 303]);

        // A disabled admin's session no longer works.
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);
        $now = new DateTimeImmutable('2026-10-06T10:00:00Z');
        $this->service($app, UserRepository::class)->setDisabledAt($owner->id, $now, $now);
        self::assertNotSame(200, $admin->get('/settings/delivery')->getStatusCode());
    }

    public function testSavingSealsThePasswordAndNeverShowsItBack(): void
    {
        $app = $this->mailApp();
        $browser = $this->signedIn($app);

        $saved = $browser->post('/settings/delivery', self::FORM);
        self::assertSame(303, $saved->getStatusCode());

        $server = $this->service($app, MailConfig::class)->effective();
        self::assertNotNull($server);
        self::assertSame('smtp.example.com', $server->host);
        self::assertSame(587, $server->port, 'the default port for STARTTLS');
        self::assertSame(MailEncryption::Tls, $server->encryption);
        self::assertSame('Our garage', $server->fromName);
        self::assertTrue($server->hasPassword);
        $stored = $this->storedPassword($app);
        self::assertNotNull($stored);
        self::assertStringStartsWith('v1:', $stored, 'sealed');
        self::assertStringNotContainsString(self::PASSWORD_TYPED, $stored);

        $page = self::body($browser->get('/settings/delivery'));
        self::assertStringNotContainsString(self::PASSWORD_TYPED, $page);
        self::assertStringContainsString('A password is saved.', $page);
        self::assertStringContainsString('Replace the password', $page);
        self::assertStringContainsString('Logbook sends email through this server.', $page);

        // A form re-shown after an error never puts a typed password back, and saves nothing.
        $refused = $browser->post('/settings/delivery', ['port' => '70000', 'password' => 'another-secret-pass'] + self::FORM);
        self::assertSame(422, $refused->getStatusCode());
        $body = self::body($refused);
        self::assertStringNotContainsString('another-secret-pass', $body);
        self::assertStringNotContainsString(self::PASSWORD_TYPED, $body);
        self::assertStringContainsString('Enter a port from 1 to 65535.', $body);
        self::assertSame($stored, $this->storedPassword($app));

        // An empty field keeps the password; *Remove* removes it.
        $browser->post('/settings/delivery', ['password' => '', 'from_name' => 'Garage'] + self::FORM);
        self::assertSame([$stored], [$this->storedPassword($app)], 'kept');
        $browser->post('/settings/delivery', ['password' => '', 'remove_password' => '1'] + self::FORM);
        self::assertNull($this->storedPassword($app));
        self::assertFalse($this->service($app, MailConfig::class)->effective()?->hasPassword);
    }

    public function testAnEnvReferenceAndNoSessionSecret(): void
    {
        $app = $this->mailApp(['SESSION_SECRET' => '', 'SMTP_SECRET_PASS' => 'from-the-environment']);
        $browser = $this->signedIn($app);
        self::assertStringContainsString('Without a SESSION_SECRET only', self::body($browser->get('/settings/delivery')));

        $refused = $browser->post('/settings/delivery', self::FORM);
        self::assertSame(422, $refused->getStatusCode(), 'no key: a typed password cannot be sealed');
        self::assertNull($this->service($app, MailConfig::class)->effective());

        $saved = $browser->post('/settings/delivery', ['password' => 'env:SMTP_SECRET_PASS'] + self::FORM);
        self::assertSame(303, $saved->getStatusCode());
        $secrets = $this->service($app, NotificationSecretRepository::class);
        self::assertSame('env:SMTP_SECRET_PASS', $secrets->find(null, 'smtp_password'));
        self::assertStringContainsString('Read from SMTP_SECRET_PASS.', self::body($browser->get('/settings/delivery')));

        // Sending reads the variable.
        $this->sendAccountMail($app);
        self::assertSame('from-the-environment', $this->transports->built[0]['password']);

        // A typed reference is read for the test too, unsaved.
        $test = ['intent' => 'test', 'test_to' => 'me@example.com', 'password' => 'env:SMTP_SECRET_PASS'] + self::FORM;
        $browser->post('/settings/delivery', $test);
        self::assertCount(2, $this->transports->built);
        self::assertSame('from-the-environment', array_column($this->transports->built, 'password')[1]);
        $unset = self::body($browser->post('/settings/delivery', ['password' => 'env:SMTP_UNSET'] + $test));
        self::assertStringContainsString('Set SMTP_UNSET', $unset);
        self::assertCount(2, $this->transports->built, 'nothing sent');

        // A reference to a variable that is not set says which one.
        $browser->post('/settings/delivery', ['password' => 'env:SMTP_MISSING'] + self::FORM);
        self::assertStringContainsString('Set SMTP_MISSING', self::body($browser->get('/settings/delivery')));
    }

    public function testTheTestUsesTypedValuesUnsavedAndGoesToTheAdmin(): void
    {
        $app = $this->mailApp();
        $browser = $this->signedIn($app);
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);
        $this->withEmail($app, $owner, 'pat@example.com');

        $response = $browser->post('/settings/delivery', ['intent' => 'test', 'host' => 'smtp.typed.example'] + self::FORM);
        self::assertSame(200, $response->getStatusCode());
        $page = self::body($response);
        self::assertStringContainsString('Test email sent to pat@example.com. Nothing was saved', $page);
        self::assertStringNotContainsString(self::PASSWORD_TYPED, $page);

        self::assertCount(1, $this->transports->built);
        self::assertSame('smtp.typed.example', $this->transports->built[0]['server']->host);
        self::assertSame(self::PASSWORD_TYPED, $this->transports->built[0]['password']);
        $sent = $this->transports->mail->sent;
        self::assertCount(1, $sent);
        self::assertSame('pat@example.com', $sent[0]->getTo()[0]->getAddress());
        self::assertSame('logbook@example.com', $sent[0]->getFrom()[0]->getAddress());
        self::assertNull($this->service($app, MailConfig::class)->effective(), 'nothing saved');
        self::assertNull($this->storedPassword($app));
    }

    public function testTheTestWithAnEmptyPasswordUsesTheSavedOne(): void
    {
        $app = $this->mailApp();
        $browser = $this->signedIn($app);
        $browser->post('/settings/delivery', self::FORM);

        $browser->post('/settings/delivery', ['intent' => 'test', 'password' => '', 'test_to' => 'me@example.com'] + self::FORM);
        self::assertSame(self::PASSWORD_TYPED, $this->transports->built[0]['password']);
        self::assertSame('me@example.com', $this->transports->mail->sent[0]->getTo()[0]->getAddress());

        // Never to another server: that one needs its password typed.
        $this->transports->built = [];
        $other = ['intent' => 'test', 'password' => '', 'test_to' => 'me@example.com', 'host' => 'elsewhere.example'];
        $other += self::FORM;
        $page = self::body($browser->post('/settings/delivery', $other));
        self::assertStringContainsString('Type the password to test another server', $page);
        self::assertSame([], $this->transports->built);
    }

    public function testWithoutAnAddressTheTestAsksForOne(): void
    {
        $app = $this->mailApp();
        $browser = $this->signedIn($app);
        self::assertStringContainsString('name="test_to"', self::body($browser->get('/settings/delivery')));

        $refused = $browser->post('/settings/delivery', ['intent' => 'test'] + self::FORM);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('Enter the address to send the test to.', self::body($refused));
        self::assertSame([], $this->transports->built);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failures(): iterable
    {
        yield 'connection' => [
            'Connection could not be established with host "smtp.example.com:587": stream_socket_client(): Connection refused',
            'Logbook could not connect to the server.',
        ];
        yield 'encryption' => ['Unable to connect with STARTTLS: certificate verify failed', 'failed at encryption'];
        yield 'sign-in' => [
            'Failed to authenticate on SMTP server with username "logbook" using the following authenticators: "LOGIN": '
                . 'Expected response code "235" but got code "535", with message "535 5.7.8 '
                . self::PASSWORD_TYPED . ' rejected".',
            'failed at sign-in',
        ];
        yield 'send' => [
            'Expected response code "250" but got code "554", with message "554 Message rejected".',
            'refused the message',
        ];
    }

    #[DataProvider('failures')]
    public function testAFailingTestNamesTheStageAndIsRedacted(string $error, string $said): void
    {
        $app = $this->mailApp();
        $browser = $this->signedIn($app);
        $this->transports->failWith = $error;

        $form = ['intent' => 'test', 'test_to' => 'me@example.com'] + self::FORM;
        $page = self::body($browser->post('/settings/delivery', $form));
        self::assertStringContainsString($said, $page);
        self::assertStringContainsString('data-test-result=', $page);
        self::assertStringNotContainsString(self::PASSWORD_TYPED, $page, 'the password is taken out of the reply');
        self::assertNull($this->service($app, MailConfig::class)->effective());
    }

    public function testRemovingTurnsEmailOff(): void
    {
        $app = $this->mailApp();
        $browser = $this->signedIn($app);
        $browser->post('/settings/delivery', self::FORM);
        self::assertStringContainsString('Remove smtp.example.com', self::body($browser->get('/settings/delivery/remove')));

        self::assertSame(303, $browser->post('/settings/delivery/remove')->getStatusCode());
        self::assertNull($this->service($app, MailConfig::class)->effective());
        self::assertNull($this->storedPassword($app));
        $page = self::body($browser->get('/settings/delivery'));
        self::assertStringContainsString('Email is off until a server is set up here.', $page);
    }

    public function testTheOldMailVariablesAreIgnoredAndNamed(): void
    {
        $app = $this->mailApp([
            'MAIL_HOST' => 'smtp.old.example',
            'MAIL_FROM' => 'old@example.com',
            'MAIL_TO' => 'me@example.com',
        ]);
        $browser = $this->signedIn($app);

        self::assertFalse($this->service($app, MailConfig::class)->isConfigured());
        self::assertSame(MailConfig::SOURCE_NONE, $this->service($app, MailConfig::class)->source());
        $page = self::body($browser->get('/settings/delivery'));
        self::assertStringContainsString('data-old-variables', $page);
        self::assertStringContainsString(
            'MAIL_HOST and other MAIL_ variables are set in the environment but are no longer read.',
            $page,
        );
        self::assertStringNotContainsString('Forgotten your password?', self::body((new TestBrowser($app))->get('/login')));
        self::assertFalse($this->sendAccountMail($app), 'nothing is sent');
        self::assertSame([], $this->transports->built);
    }

    public function testEveryEmailFollowsAChangeAtOnce(): void
    {
        $app = $this->mailApp();
        $browser = $this->signedIn($app);
        $login = fn (): string => self::body((new TestBrowser($app))->get('/login'));
        self::assertStringNotContainsString('Forgotten your password?', $login());
        $users = self::body($browser->get('/settings/users'));
        self::assertStringNotContainsString('name="email"', $users, 'no invitation by email');

        $browser->post('/settings/delivery', self::FORM);
        self::assertStringContainsString('Forgotten your password?', $login());
        self::assertStringContainsString('name="email"', self::body($browser->get('/settings/users')), 'invitations by email');
        self::assertTrue($this->sendAccountMail($app));
        self::assertSame('smtp.example.com', $this->transports->built[0]['server']->host);
        self::assertSame(self::PASSWORD_TYPED, $this->transports->built[0]['password']);
        $sent = $this->transports->mail->sent[0];
        self::assertInstanceOf(Email::class, $sent);
        self::assertSame('Our garage', $sent->getFrom()[0]->getName());

        $browser->post('/settings/delivery', ['host' => 'smtp.other.example', 'password' => ''] + self::FORM);
        $this->sendAccountMail($app);
        self::assertSame('smtp.other.example', $this->transports->built[1]['server']->host, 'the same process sees the change');

        $browser->post('/settings/delivery/remove');
        self::assertStringNotContainsString('Forgotten your password?', $login());
    }

    public function testAPasswordSealedWithAnotherKeyIsNotUsed(): void
    {
        $app = $this->mailApp();
        $browser = $this->signedIn($app);
        $browser->post('/settings/delivery', self::FORM);

        $other = $this->mailApp(['SESSION_SECRET' => str_repeat('f', 64)]);
        $owner = $this->browserFor($other, 'owner');
        self::assertStringContainsString('Re-enter the password', self::body($owner->get('/settings/delivery')));
        self::assertFalse($this->sendAccountMail($other), 'nothing is sent with it');
        self::assertSame([], $this->transports->built);
    }

    /**
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    private function mailApp(array $env = []): App
    {
        $app = $this->createApp($env + ['SESSION_SECRET' => self::KEY, 'APP_URL' => 'https://garage.example']);
        $this->transports = new RecordingTransportFactory();
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(TransportFactory::class, $this->transports);

        return $app;
    }

    /**
     * The stored `smtp_password` row's value, as it is now.
     *
     * @param App<ContainerInterface> $app
     * @phpstan-impure
     */
    private function storedPassword(App $app): ?string
    {
        return $this->service($app, NotificationSecretRepository::class)->find(null, 'smtp_password');
    }

    /**
     * Send one account email through the app's real transport.
     *
     * @param App<ContainerInterface> $app
     */
    private function sendAccountMail(App $app): bool
    {
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);

        return $this->service($app, AccountMailer::class)->send(
            $owner,
            'pat@example.com',
            static fn (): AccountMail => new AccountMail('Subject', ['Hello.'], null, null),
        );
    }
}
