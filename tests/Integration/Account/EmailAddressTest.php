<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Account;

use DateInterval;
use Logbook\Domain\User\User;
use Logbook\Repository\UserRepository;
use Logbook\Tests\Support\AccountTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Email addresses (spec.md §7.9 *Email addresses*, *Sign-in by username or
 * email*, Phase 33.1): a change needs the current password, waits for its
 * link and tells the old address; only a confirmed address signs in, and
 * only when one user has it.
 */
final class EmailAddressTest extends AccountTestCase
{
    private const string NOW = '2026-10-05T09:00:00Z';

    public function testAChangeWaitsForItsLinkAndTellsTheOldAddress(): void
    {
        $app = $this->accountApp();
        $clock = $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->withEmail($app, $this->owner($app), 'old@example.com');

        $wrong = $browser->post('/settings/email', ['email' => 'new@example.com', 'email_password' => 'not it']);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString('Your current password is not correct.', self::body($wrong));
        $invalid = $browser->post('/settings/email', ['email' => 'not an address', 'email_password' => self::PASSWORD]);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertNull($this->user($app)->emailPending);

        $saved = $browser->post('/settings/email', ['email' => ' New@Example.com', 'email_password' => self::PASSWORD]);
        self::assertSame(303, $saved->getStatusCode());
        $user = $this->user($app);
        self::assertSame('old@example.com', $user->email, 'the old address stays in use until confirmed');
        self::assertSame('new@example.com', $user->emailPending);
        self::assertStringContainsString('Waiting for confirmation: new@example.com', self::body($browser->follow($saved)));

        $notice = $this->mailTo('old@example.com');
        self::assertCount(1, $notice);
        self::assertStringContainsString('to new@example.com', (string) $notice[0]->getTextBody());
        $confirm = $this->mailTo('new@example.com');
        self::assertCount(1, $confirm);
        self::assertSame('Confirm your Logbook email address', $confirm[0]->getSubject());
        $path = self::linkIn($confirm[0]);
        self::assertStringStartsWith('/confirm-email/', $path);

        // A pending address is used for nothing: no sign-in, no reset link.
        self::assertSame(422, $this->signIn($app, 'new@example.com')->getStatusCode());

        $guest = new TestBrowser($app);
        self::assertStringContainsString('Confirm new@example.com', self::body($guest->get($path)));
        self::assertSame(200, $guest->get($path)->getStatusCode(), 'opening it confirms nothing');
        self::assertSame('new@example.com', $this->user($app)->emailPending);

        $done = $guest->post($path);
        self::assertSame(303, $done->getStatusCode());
        self::assertStringEndsWith('/login', $done->getHeaderLine('Location'), 'not signed in here: to sign-in');
        $user = $this->user($app);
        self::assertSame('new@example.com', $user->email);
        self::assertNull($user->emailPending);
        self::assertSame(404, (new TestBrowser($app))->get($path)->getStatusCode(), 'used once');
        self::assertSame(303, $this->signIn($app, 'new@example.com')->getStatusCode(), 'a confirmed address signs in');

        // A replaced, a cancelled and an expired link all answer 404.
        $browser->post('/settings/email', ['email' => 'first@example.com', 'email_password' => self::PASSWORD]);
        $first = self::linkIn($this->mailTo('first@example.com')[0]);
        $browser->post('/settings/email/resend');
        self::assertCount(2, $this->mailTo('first@example.com'));
        self::assertSame(404, (new TestBrowser($app))->get($first)->getStatusCode(), 'replaced by the new link');
        $second = self::linkIn($this->mailTo('first@example.com')[1]);
        $browser->post('/settings/email/cancel');
        self::assertNull($this->user($app)->emailPending);
        self::assertSame(404, (new TestBrowser($app))->get($second)->getStatusCode(), 'cancelled');

        $browser->post('/settings/email', ['email' => 'late@example.com', 'email_password' => self::PASSWORD]);
        $late = self::linkIn($this->mailTo('late@example.com')[0]);
        $clock->set($clock->now()->add(new DateInterval('PT24H')));
        self::assertSame(404, (new TestBrowser($app))->get($late)->getStatusCode(), 'a day, then gone');

        $this->mail->sent = [];
        $browser->post('/settings/email', ['email' => '', 'email_password' => self::PASSWORD]);
        $user = $this->user($app);
        self::assertNull($user->email, 'removed');
        self::assertNull($user->emailPending);
        self::assertCount(1, $this->mailTo('new@example.com'), 'the old address hears it was removed');
    }

    public function testSignedInTheLinkGoesBackToSettings(): void
    {
        $app = $this->accountApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $browser->post('/settings/email', ['email' => 'pat@example.com', 'email_password' => self::PASSWORD]);
        $path = self::linkIn($this->mailTo('pat@example.com')[0]);

        $browser->get($path);
        $done = $browser->post($path);
        self::assertStringEndsWith('/settings', $done->getHeaderLine('Location'));
        self::assertStringContainsString('pat@example.com is confirmed.', self::body($browser->follow($done)));
    }

    public function testWithoutEmailSetUpAnAddressStaysPending(): void
    {
        $app = $this->accountApp(['MAIL_HOST' => '']);
        $browser = $this->signedIn($app);
        $page = self::body($browser->get('/settings'));
        self::assertStringContainsString('so a new address can’t be confirmed yet', $page);

        $saved = $browser->post('/settings/email', ['email' => 'pat@example.com', 'email_password' => self::PASSWORD]);
        self::assertStringContainsString('could not be sent', self::body($browser->follow($saved)));
        self::assertSame('pat@example.com', $this->user($app)->emailPending);
        self::assertNull($this->user($app)->email);
    }

    public function testRemindersGoToTheConfirmedAddressAndTheReminderPageLinksToIt(): void
    {
        $app = $this->accountApp();
        $browser = $this->signedIn($app);
        self::assertStringContainsString('No address on your account yet', self::body($browser->get('/settings/reminders')));
        $this->withEmail($app, $this->owner($app), 'pat@example.com');
        $page = self::body($browser->get('/settings/reminders'));
        self::assertStringContainsString('<strong>pat@example.com</strong>', $page);
        self::assertStringNotContainsString('name="email"', $page, 'no field of its own any more');
    }

    public function testSignInByEmailNeedsOneConfirmedHolder(): void
    {
        $app = $this->accountApp();
        $this->signedIn($app);
        $this->withEmail($app, $this->owner($app), 'pat@example.com');

        self::assertSame(303, $this->signIn($app, 'Pat@Example.com')->getStatusCode(), 'case and spaces do not matter');
        self::assertSame(303, $this->signIn($app, 'owner')->getStatusCode(), 'the username still works');

        $this->withEmail($app, $this->createMember($app), 'pat@example.com');
        $shared = $this->signIn($app, 'pat@example.com');
        self::assertSame(422, $shared->getStatusCode(), 'a shared address is ambiguous');
        self::assertStringContainsString('That username and password do not match.', self::body($shared));
        self::assertSame(303, $this->signIn($app, 'partner')->getStatusCode(), 'they sign in by username');

        // A username with an @ wins over someone else's address.
        $this->createMember($app, 'sam@example.com', displayName: 'Sam At');
        $this->withEmail($app, $this->createMember($app, 'robin'), 'sam@example.com');
        $browser = new TestBrowser($app);
        $browser->get('/login');
        $browser->post('/login', ['username' => 'sam@example.com', 'password' => self::PASSWORD]);
        self::assertStringContainsString('Sam At', self::body($browser->get('/settings')));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function signIn(App $app, string $login): \Psr\Http\Message\ResponseInterface
    {
        $browser = new TestBrowser($app);
        $browser->get('/login');

        return $browser->post('/login', ['username' => $login, 'password' => self::PASSWORD]);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function user(App $app): User
    {
        $user = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($user);

        return $user;
    }
}
