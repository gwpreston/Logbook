<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Account;

use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\User\User;
use Logbook\Repository\SessionRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Api\ApiKeyService;
use Logbook\Service\Sharing\SharingService;
use Logbook\Tests\Support\AccountTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Admin controls on Settings → Users (spec.md §7.9, Phase 33.1): *Send
 * reset email*, *Sign out everywhere*, *Revoke access* and *Add user*,
 * for admins only.
 */
final class AdminControlsTest extends AccountTestCase
{
    private const string NEW_PASSWORD = 'their very own passphrase';

    public function testAMemberCanDoNoneOfIt(): void
    {
        $app = $this->accountApp();
        $this->signedIn($app);
        $owner = $this->owner($app);
        $this->createMember($app);
        $member = $this->browserFor($app, 'partner');

        self::assertSame(403, $member->get('/settings/users/add')->getStatusCode());
        self::assertSame(
            403,
            $member->post('/settings/users/add', ['username' => 'x', 'email' => 'x@example.com'])->getStatusCode(),
        );
        foreach (['disable', 'sign-out'] as $action) {
            $path = '/settings/users/' . $owner->id . '/' . $action;
            self::assertSame(403, $member->get($path)->getStatusCode(), $action);
            self::assertSame(403, $member->post($path)->getStatusCode(), $action);
        }
        self::assertSame(403, $member->post('/settings/users/' . $owner->id . '/reset')->getStatusCode());
        self::assertSame([], $this->mail->sent);
        self::assertNotNull(
            $this->service($app, SessionRepository::class)->lastActivityByUser()[$owner->id] ?? null,
            'nobody signed out',
        );
    }

    public function testSendResetEmailMailsTheLinkOrShowsItButNeverBoth(): void
    {
        $app = $this->accountApp();
        $admin = $this->signedIn($app);
        $partner = $this->withEmail($app, $this->createMember($app), 'sam@example.com');
        $nobody = $this->createMember($app, 'noaddress');

        $admin->get('/settings/users');
        self::assertStringContainsString('Send reset email', self::body($admin->get('/settings/users')));
        $emailed = $admin->post('/settings/users/' . $partner->id . '/reset');
        self::assertSame(303, $emailed->getStatusCode(), 'emailed, so not shown');
        self::assertStringContainsString(
            'A reset link went to Sam Partner at sam@example.com.',
            self::body($admin->follow($emailed)),
        );
        $sent = $this->mailTo('sam@example.com');
        self::assertCount(1, $sent);
        self::assertStringContainsString('7 days', (string) $sent[0]->getTextBody());
        $path = self::linkIn($sent[0]);
        $guest = new TestBrowser($app);
        self::assertSame(
            303,
            $guest->post($path, ['password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD])->getStatusCode(),
        );

        $shown = $admin->post('/settings/users/' . $nobody->id . '/reset');
        self::assertSame(200, $shown->getStatusCode(), 'no address: the link is shown once');
        self::assertStringContainsString('/invite/', self::body($shown));
        self::assertCount(2, $this->mail->sent, 'and nothing more was emailed (the password-changed notice only)');
    }

    public function testSignOutEverywhereEndsEverySessionButDisablesNobody(): void
    {
        $app = $this->accountApp();
        $admin = $this->signedIn($app);
        $partner = $this->createMember($app);
        $phone = $this->browserFor($app, 'partner');
        $laptop = $this->browserFor($app, 'partner');

        $confirm = self::body($admin->get('/settings/users/' . $partner->id . '/sign-out'));
        self::assertStringContainsString('Sign Sam Partner out everywhere?', $confirm);
        self::assertSame(303, $admin->post('/settings/users/' . $partner->id . '/sign-out')->getStatusCode());
        foreach ([$phone, $laptop] as $browser) {
            self::assertStringContainsString('/login', $browser->get('/garage')->getHeaderLine('Location'));
        }
        self::assertTrue($this->fresh($app, $partner->id)->isActive(), 'not disabled');
        $this->browserFor($app, 'partner');

        // Oneself: this session too.
        $owner = $this->owner($app);
        $out = $admin->post('/settings/users/' . $owner->id . '/sign-out');
        self::assertStringEndsWith('/login', $out->getHeaderLine('Location'));
        self::assertStringContainsString('/login', $admin->get('/garage')->getHeaderLine('Location'));
        self::assertArrayNotHasKey($owner->id, $this->service($app, SessionRepository::class)->lastActivityByUser());
    }

    public function testRevokeAccessIsDisableUnderItsNewName(): void
    {
        $app = $this->accountApp();
        $admin = $this->signedIn($app);
        $partner = $this->createMember($app);
        $session = $this->browserFor($app, 'partner');
        $token = $this->service($app, ApiKeyService::class)->create($partner, 'Phone', ApiScope::Read)->token;
        $bearer = ['Authorization' => 'Bearer ' . $token];

        $page = self::body($admin->get('/settings/users'));
        self::assertStringContainsString('Revoke access', $page);
        self::assertStringContainsString(
            'Revoke Sam Partner’s access?',
            self::body($admin->get('/settings/users/' . $partner->id . '/disable')),
        );
        $admin->post('/settings/users/' . $partner->id . '/disable');
        self::assertFalse($this->fresh($app, $partner->id)->isActive());
        self::assertStringContainsString('/login', $session->get('/garage')->getHeaderLine('Location'));
        self::assertSame(401, $this->get($app, '/api/v1/me', $bearer)->getStatusCode());
        self::assertStringContainsString('Restore access', self::body($admin->get('/settings/users')));

        $admin->post('/settings/users/' . $partner->id . '/enable');
        self::assertSame(200, $this->get($app, '/api/v1/me', $bearer)->getStatusCode(), 'as Enable: the key works again (#158)');

        $owner = $this->owner($app);
        self::assertStringContainsString(
            'You cannot revoke your own access.',
            self::body($admin->get('/settings/users/' . $owner->id . '/disable')),
        );
        $admin->post('/settings/users/' . $owner->id . '/disable');
        self::assertTrue($this->fresh($app, $owner->id)->isActive(), 'never oneself, never the last admin');
    }

    public function testAddUserCreatesTheAccountAtOnceAndTheirLinkConfirmsTheAddress(): void
    {
        $app = $this->accountApp();
        $admin = $this->signedIn($app);
        $golf = $this->vehicle($app);

        self::assertStringContainsString('Add and email them', self::body($admin->get('/settings/users/add')));
        $invalid = $admin->post('/settings/users/add', ['username' => 'robin', 'email' => 'nope']);
        self::assertSame(422, $invalid->getStatusCode());
        $added = $admin->post('/settings/users/add', [
            'username' => 'Robin',
            'display_name' => 'Robin Driver',
            'email' => 'Robin@Example.com',
        ]);
        self::assertSame(303, $added->getStatusCode());
        self::assertStringContainsString('Robin Driver is added.', self::body($admin->follow($added)));

        $robin = $this->service($app, UserRepository::class)->findByUsername('robin');
        self::assertNotNull($robin);
        self::assertFalse($robin->hasPassword());
        self::assertFalse($robin->isAdmin);
        self::assertNull($robin->email);
        self::assertSame('robin@example.com', $robin->emailPending, 'pending until the link is used');
        self::assertNull(
            $this->service($app, SharingService::class)->add($golf, 'robin', ShareLevel::View, false, false),
            'shared with before they ever sign in',
        );

        $sent = $this->mailTo('robin@example.com');
        self::assertCount(1, $sent);
        self::assertSame('Pat Owner added you to Logbook', $sent[0]->getSubject());
        $guest = new TestBrowser($app);
        $path = self::linkIn($sent[0]);
        self::assertSame(
            303,
            $guest->post($path, ['password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD])->getStatusCode(),
        );
        $robin = $this->fresh($app, $robin->id);
        self::assertTrue($robin->hasPassword());
        self::assertSame('robin@example.com', $robin->email, 'using the link confirmed it (#165)');
        self::assertNull($robin->emailPending);
        self::assertSame(200, $guest->get('/vehicles/' . $golf->id)->getStatusCode());
    }

    public function testAddUserNeedsEmail(): void
    {
        $app = $this->accountApp(['MAIL_HOST' => '']);
        $admin = $this->signedIn($app);
        self::assertStringContainsString('Use an invitation link instead.', self::body($admin->get('/settings/users/add')));
        self::assertSame(
            422,
            $admin->post('/settings/users/add', ['username' => 'robin', 'email' => 'robin@example.com'])->getStatusCode(),
        );
        self::assertNull($this->service($app, UserRepository::class)->findByUsername('robin'));
    }

    public function testASelfServiceLinkIsListedAsRequestedByThem(): void
    {
        $app = $this->accountApp();
        $admin = $this->signedIn($app);
        $this->withEmail($app, $this->createMember($app), 'sam@example.com');
        $guest = new TestBrowser($app);
        $guest->get('/forgot-password');
        $guest->post('/forgot-password', ['login' => 'partner']);

        $page = self::body($admin->get('/settings/users'));
        self::assertStringContainsString('Requested by them', $page);
        self::assertSame(1, preg_match('~/settings/users/links/(\d+)/revoke~', $page, $m));
        $admin->post('/settings/users/links/' . ($m[1] ?? '') . '/revoke');
        $this->afterResponse($app);
        self::assertSame(404, (new TestBrowser($app))->get(self::linkIn($this->mailTo('sam@example.com')[0]))->getStatusCode());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function fresh(App $app, int $id): User
    {
        $user = $this->service($app, UserRepository::class)->find($id);
        self::assertNotNull($user);

        return $user;
    }
}
