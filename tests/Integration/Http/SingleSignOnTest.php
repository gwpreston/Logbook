<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\User\User;
use Logbook\Domain\User\UserIdentity;
use Logbook\Repository\UserIdentityRepository;
use Logbook\Repository\UserRepository;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\FakeIdentityProvider;
use Logbook\Tests\Support\SingleSignOn;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Single sign-on with OpenID Connect (spec.md §7.9): the flow end to end
 * against an in-process provider, finding the user, groups, linking, and
 * every failure ending in a generic message with nobody signed in.
 */
final class SingleSignOnTest extends AppTestCase
{
    use SingleSignOn;

    protected function tearDown(): void
    {
        $this->removeOidcCaches();
        parent::tearDown();
    }

    public function testALinkedUserSignsInWithTheProvider(): void
    {
        [$app, $idp] = $this->ssoApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $this->link($app, $owner, 'sub-owner');

        $browser = new TestBrowser($app);
        $login = self::body($browser->get('/login'));
        self::assertStringContainsString('Sign in with Authentik', $login);
        self::assertStringContainsString('name="password"', $login, 'local sign-in stays on by default');

        $start = $browser->get('/auth/oidc/start');
        self::assertSame(303, $start->getStatusCode());
        $location = $start->getHeaderLine('Location');
        self::assertStringStartsWith(FakeIdentityProvider::ISSUER . '/protocol/openid-connect/auth?', $location);
        $query = FakeIdentityProvider::query($location);
        self::assertSame('code', $query['response_type']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame(FakeIdentityProvider::CLIENT_ID, $query['client_id']);
        self::assertSame('openid profile email', $query['scope']);
        self::assertSame('http://localhost:8080/auth/oidc/callback', $query['redirect_uri']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['state']);
        self::assertNotSame($query['state'], $query['nonce']);
        $before = $browser->sessionCookie();
        self::assertNotNull($before, 'the flow lives in a pre-sign-in session');

        $callback = $browser->get($idp->authorize($location, ['sub' => 'sub-owner']));
        self::assertSame(303, $callback->getStatusCode());
        self::assertSame('/', $callback->getHeaderLine('Location'));
        self::assertNotSame($before, $browser->sessionCookie(), 'a new session id at sign-in (fixation)');
        self::assertSame(200, $browser->get('/')->getStatusCode());

        $token = $idp->requestsTo('/token')[0];
        $credentials = rawurlencode(FakeIdentityProvider::CLIENT_ID) . ':' . rawurlencode(FakeIdentityProvider::CLIENT_SECRET);
        self::assertSame(
            'Basic ' . base64_encode($credentials),
            $token['headers']['authorization'][0] ?? null,
            'client_secret_basic, each part form-urlencoded',
        );
        self::assertStringNotContainsString('client_secret', $token['body']);
        $identity = $this->identities($app)->findBySubject(UserIdentity::OIDC, FakeIdentityProvider::ISSUER, 'sub-owner');
        self::assertNotNull($identity?->lastLoginAt);
    }

    public function testThePageAskedForIsKeptAndOtherPlacesAreIgnored(): void
    {
        [$app, $idp] = $this->ssoApp();
        $this->resetDatabase($app);
        $this->link($app, $this->createOwner($app), 'sub-owner');

        $garage = $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-owner'], '/garage?view=list');
        self::assertSame('/garage?view=list', $garage->getHeaderLine('Location'));
        foreach (['//evil.example/', 'https://evil.example/', '/\\evil.example', 'javascript:alert(1)'] as $next) {
            $response = $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-owner'], $next);
            self::assertSame('/', $response->getHeaderLine('Location'), $next . ' is not followed');
        }
    }

    public function testAnUnlinkedAccountReachesNobodyByDefault(): void
    {
        [$app, $idp] = $this->ssoApp();
        $this->resetDatabase($app);
        $this->createOwner($app);

        $browser = new TestBrowser($app);
        $response = $this->ssoSignIn($browser, $idp, ['sub' => 'stranger', 'preferred_username' => 'owner']);

        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertStringContainsString(
            'Your Authentik account isn&#039;t linked to Logbook. Ask an admin to invite you',
            self::body($browser->follow($response)),
        );
        self::assertSame(303, $browser->get('/')->getStatusCode(), 'not signed in');
        self::assertSame([], $this->identities($app)->forUser($this->owner($app)->id), 'an equal username links nothing');
    }

    public function testUsernameLinkingLinksAUserWithoutAnIdentityOnce(): void
    {
        [$app, $idp] = $this->ssoApp(['OIDC_LINK' => 'username']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        $first = $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-a', 'preferred_username' => 'Owner']);
        self::assertSame('/', $first->getHeaderLine('Location'), 'linked by username (lower-cased)');
        self::assertCount(1, $this->identities($app)->forUser($owner->id));

        $second = $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-b', 'preferred_username' => 'owner']);
        self::assertSame('/login', $second->getHeaderLine('Location'), 'not a user who already has an identity');
        self::assertCount(1, $this->identities($app)->forUser($owner->id));
    }

    public function testTheUsernameComesFromUserinfoWhenTheIdTokenLeavesItOut(): void
    {
        [$app, $idp] = $this->ssoApp(['OIDC_LINK' => 'username']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $idp->userinfo = ['preferred_username' => 'owner'];

        $response = $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-a']);

        self::assertSame('/', $response->getHeaderLine('Location'));
        self::assertCount(1, $idp->requestsTo('/userinfo'));
        self::assertSame('Bearer access-', substr($idp->requestsTo('/userinfo')[0]['headers']['authorization'][0] ?? '', 0, 14));
        self::assertCount(1, $this->identities($app)->forUser($owner->id));
    }

    public function testUserinfoForAnotherSubjectIsRefused(): void
    {
        [$app, $idp] = $this->ssoApp(['OIDC_LINK' => 'username']);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $idp->userinfo = ['preferred_username' => 'owner', 'sub' => 'someone-else'];

        $response = $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-a']);

        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertSame([], $this->identities($app)->forUser($this->owner($app)->id));
    }

    public function testAutomaticCreationMakesAMemberWithoutAPasswordAndAsksForPreferences(): void
    {
        [$app, $idp] = $this->ssoApp(['OIDC_AUTO_CREATE' => 'true']);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $this->createMember($app, 'sam.smith');

        $browser = new TestBrowser($app);
        $response = $this->ssoSignIn($browser, $idp, [
            'sub' => 'sub-new',
            'preferred_username' => 'Sam Smith!',
            'name' => 'Sam Smith',
            'locale' => 'de',
        ], '/garage');
        self::assertSame('/welcome', $response->getHeaderLine('Location'));

        $user = $this->users($app)->findByUsername('sam-smith');
        self::assertNotNull($user, 'the username claim, sanitised');
        self::assertFalse($user->hasPassword());
        self::assertFalse($user->isAdmin);
        self::assertSame('Sam Smith', $user->displayName);
        self::assertSame('de', $user->preferences->locale);

        $welcome = $browser->get('/welcome');
        self::assertSame(200, $welcome->getStatusCode());
        $done = $browser->post('/welcome', [
            'units' => 'uk', 'currency' => 'EUR', 'locale' => 'en_GB', 'timezone' => 'Europe/Berlin',
        ]);
        self::assertSame('/garage', $done->getHeaderLine('Location'), 'then the page asked for');
        $saved = $this->users($app)->findByUsername('sam-smith');
        self::assertSame('Europe/Berlin', $saved?->preferences->timezone);
        self::assertSame('EUR', $saved->preferences->currency);
        self::assertSame(303, $browser->get('/welcome')->getStatusCode(), 'once');

        $guest = new TestBrowser($app);
        $guest->get('/login');
        self::assertSame(422, $guest->post('/login', ['username' => 'sam-smith', 'password' => ''])->getStatusCode());

        $again = $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-other', 'preferred_username' => 'sam-smith']);
        self::assertSame('/welcome', $again->getHeaderLine('Location'));
        self::assertNotNull($this->users($app)->findByUsername('sam-smith-2'), 'a taken username gets a suffix');
    }

    public function testWithoutAutomaticCreationAnUnknownAccountIsRefused(): void
    {
        [$app, $idp] = $this->ssoApp(['OIDC_AUTO_CREATE' => 'false']);
        $this->resetDatabase($app);
        $this->createOwner($app);

        self::assertSame('/login', $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'x'])->getHeaderLine('Location'));
        self::assertCount(1, $this->users($app)->listAll());
    }

    public function testAllowedGroupsKeepEveryoneElseOut(): void
    {
        [$app, $idp] = $this->ssoApp(['OIDC_ALLOWED_GROUPS' => 'logbook, family', 'OIDC_AUTO_CREATE' => 'true']);
        $this->resetDatabase($app);
        $this->link($app, $this->createOwner($app), 'sub-owner');

        $outside = $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-owner', 'groups' => ['work']]);
        self::assertSame('/login', $outside->getHeaderLine('Location'), 'even a linked user');
        $none = $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-new', 'preferred_username' => 'newbie']);
        self::assertSame('/login', $none->getHeaderLine('Location'), 'and nobody is created');
        self::assertNull($this->users($app)->findByUsername('newbie'));

        $inside = $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-owner', 'groups' => ['family']]);
        self::assertSame('/', $inside->getHeaderLine('Location'));
        $single = $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-owner', 'groups' => 'logbook']);
        self::assertSame('/', $single->getHeaderLine('Location'), 'a groups claim may be one string');
    }

    public function testAdminFollowsTheAdminGroupsBothWaysExceptForTheLastAdmin(): void
    {
        [$app, $idp] = $this->ssoApp(['OIDC_ADMIN_GROUPS' => 'logbook-admins']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $member = $this->createMember($app);
        $this->link($app, $owner, 'sub-owner');
        $this->link($app, $member, 'sub-member');

        $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-member', 'groups' => ['logbook-admins']]);
        self::assertTrue($this->users($app)->find($member->id)?->isAdmin ?? false, 'promoted');

        $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-member', 'groups' => []]);
        self::assertFalse($this->users($app)->find($member->id)?->isAdmin ?? true, 'demoted');

        $last = $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-owner', 'groups' => ['other']]);
        self::assertSame('/', $last->getHeaderLine('Location'));
        self::assertTrue($this->users($app)->find($owner->id)?->isAdmin, 'the last admin is never demoted');
    }

    public function testWithoutAdminGroupsTheGroupsChangeNothing(): void
    {
        [$app, $idp] = $this->ssoApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $member = $this->createMember($app);
        $this->link($app, $member, 'sub-member');

        $this->ssoSignIn(new TestBrowser($app), $idp, ['sub' => 'sub-member', 'groups' => ['admins', 'logbook-admins']]);

        self::assertFalse($this->users($app)->find($member->id)?->isAdmin);
    }

    public function testADisabledUserIsRefused(): void
    {
        [$app, $idp] = $this->ssoApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $member = $this->createMember($app);
        $this->link($app, $member, 'sub-member');
        $this->users($app)->setDisabledAt($member->id, new \DateTimeImmutable(), new \DateTimeImmutable());

        $browser = new TestBrowser($app);
        $response = $this->ssoSignIn($browser, $idp, ['sub' => 'sub-member']);

        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Sign-in with Authentik didn&#039;t work.', self::body($browser->follow($response)));
        self::assertSame(303, $browser->get('/')->getStatusCode());
        self::assertTrue($owner->isAdmin);
    }

    public function testStateIsSingleUseAndLastsTenMinutes(): void
    {
        [$app, $idp] = $this->ssoApp();
        $clock = $this->pinClock($app, '2026-10-01T10:00:00Z');
        $idp->now = $clock->now()->getTimestamp();
        $this->resetDatabase($app);
        $this->link($app, $this->createOwner($app), 'sub-owner');

        $browser = new TestBrowser($app);
        $callback = $idp->authorize($browser->get('/auth/oidc/start')->getHeaderLine('Location'), ['sub' => 'sub-owner']);
        $replayer = clone $browser;
        self::assertSame('/', $browser->get($callback)->getHeaderLine('Location'));
        self::assertSame('/login', $replayer->get($callback)->getHeaderLine('Location'), 'a replayed state is refused');

        $late = new TestBrowser($app);
        $callback = $idp->authorize($late->get('/auth/oidc/start')->getHeaderLine('Location'), ['sub' => 'sub-owner']);
        $clock->set($clock->now()->modify('+601 seconds'));
        $idp->now = $clock->now()->getTimestamp();
        self::assertSame('/login', $late->get($callback)->getHeaderLine('Location'), 'older than 10 minutes');
        self::assertSame(303, $late->get('/')->getStatusCode());

        $other = new TestBrowser($app);
        $other->get('/login');
        self::assertSame('/login', $other->get($callback)->getHeaderLine('Location'), 'a state from another session');
    }

    public function testAStateIsUsedUpEvenWhenTheCallbackFails(): void
    {
        [$app, $idp] = $this->ssoApp();
        $this->resetDatabase($app);
        $this->link($app, $this->createOwner($app), 'sub-owner');

        $browser = new TestBrowser($app);
        $callback = $idp->authorize($browser->get('/auth/oidc/start')->getHeaderLine('Location'), ['sub' => 'sub-owner']);
        parse_str((string) parse_url($callback, PHP_URL_QUERY), $query);
        $bogus = '/auth/oidc/callback?' . http_build_query(['code' => 'not-a-code', 'state' => $query['state']]);

        self::assertSame('/login', $browser->get($bogus)->getHeaderLine('Location'), 'the exchange fails');
        self::assertSame('/login', $browser->get($callback)->getHeaderLine('Location'), 'the same state again, with a good code');
        self::assertSame(303, $browser->get('/')->getStatusCode());
    }

    public function testEveryFailureShowsAGenericMessageAndSignsNobodyIn(): void
    {
        [$app, $idp] = $this->ssoApp();
        $this->resetDatabase($app);
        $this->link($app, $this->createOwner($app), 'sub-owner');

        $denied = new TestBrowser($app);
        $start = $denied->get('/auth/oidc/start')->getHeaderLine('Location');
        $response = $denied->get($idp->authorize($start, ['sub' => 'sub-owner'], ['error' => 'access_denied', 'code' => '']));
        $page = self::body($denied->follow($response));
        self::assertStringContainsString(
            'Sign-in with Authentik didn&#039;t work. Try again, or sign in with your password.',
            $page,
        );
        self::assertStringNotContainsString('access_denied', $page);

        $idp->tamperClaims = static fn (array $claims): array => ['aud' => 'someone-else'] + $claims;
        $browser = new TestBrowser($app);
        $page = self::body($browser->follow($this->ssoSignIn($browser, $idp, ['sub' => 'sub-owner'])));
        self::assertStringContainsString('didn&#039;t work', $page);
        self::assertStringNotContainsString('aud', strip_tags($page));
        self::assertSame(303, $browser->get('/')->getStatusCode());
    }

    public function testAProviderThatCannotBeReachedSendsYouBackToSignIn(): void
    {
        [$app, $idp] = $this->ssoApp();
        $idp->discoveryOverrides = ['issuer' => 'https://somewhere.else/'];
        $this->resetDatabase($app);
        $this->createOwner($app);

        $browser = new TestBrowser($app);
        $start = $browser->get('/auth/oidc/start');

        self::assertSame('/login', $start->getHeaderLine('Location'));
        self::assertStringContainsString('Authentik can&#039;t be reached just now', self::body($browser->follow($start)));
    }

    public function testWithoutSsoConfiguredItsRoutesAreNotFound(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);

        self::assertSame(404, $this->get($app, '/auth/oidc/start')->getStatusCode());
        self::assertSame(404, $this->get($app, '/auth/oidc/callback?code=x&state=y')->getStatusCode());
        self::assertStringNotContainsString('Sign in with', self::body($this->get($app, '/login')));
    }

    public function testASignedInUserLinksTheirAccountFromSettings(): void
    {
        [$app, $idp] = $this->ssoApp();
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);
        $member = $this->createMember($app);

        $settings = self::body($browser->get('/settings'));
        self::assertStringContainsString('Link Authentik account', $settings);
        $start = $browser->post('/settings/sso/link');
        self::assertSame(303, $start->getStatusCode());
        self::assertStringStartsWith(FakeIdentityProvider::ISSUER, $start->getHeaderLine('Location'));
        $done = $browser->get($idp->authorize($start->getHeaderLine('Location'), ['sub' => 'sub-owner']));
        self::assertSame('/settings', $done->getHeaderLine('Location'));
        self::assertStringContainsString('Your Authentik account is linked.', self::body($browser->follow($done)));
        self::assertCount(1, $this->identities($app)->forUser($owner->id));

        $second = $browser->post('/settings/sso/link')->getHeaderLine('Location');
        $again = $browser->get($idp->authorize($second, ['sub' => 'sub-2']));
        self::assertStringContainsString('You already have a Authentik account linked.', self::body($browser->follow($again)));

        $partner = $this->browserFor($app, 'partner');
        $theirStart = $partner->post('/settings/sso/link')->getHeaderLine('Location');
        $taken = $partner->get($idp->authorize($theirStart, ['sub' => 'sub-owner']));
        self::assertStringContainsString('already linked to another Logbook user', self::body($partner->follow($taken)));
        self::assertSame([], $this->identities($app)->forUser($member->id));

        // Unlinking: the owner still has a password, so it may go.
        $identity = $this->identities($app)->forUser($owner->id)[0];
        $browser->get('/settings');
        $unlink = $browser->post('/settings/sso/' . $identity->id . '/unlink');
        self::assertStringContainsString('is unlinked', self::body($browser->follow($unlink)));
        self::assertSame([], $this->identities($app)->forUser($owner->id));
    }

    public function testALinkCallbackInAnotherSessionLinksNothing(): void
    {
        [$app, $idp] = $this->ssoApp();
        $browser = $this->signedIn($app);
        $start = $browser->post('/settings/sso/link')->getHeaderLine('Location');

        $browser->get('/settings');
        $browser->post('/logout');
        $response = $browser->get($idp->authorize($start, ['sub' => 'sub-owner']));

        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertSame([], $this->identities($app)->forUser($this->owner($app)->id));
    }

    public function testALinkFlowFinishedByAnotherUserInTheSameBrowserLinksNothing(): void
    {
        [$app, $idp] = $this->ssoApp();
        $browser = $this->signedIn($app);
        $this->createMember($app);
        $start = $browser->post('/settings/sso/link')->getHeaderLine('Location');

        // Someone signs in as the partner in the same browser (a break-glass link keeps the session's data).
        $partner = $this->users($app)->findByUsername('partner');
        self::assertNotNull($partner);
        $link = $this->service($app, \Logbook\Service\Auth\LoginLinks::class)->create($partner);
        self::assertNotNull($link);
        $path = (string) parse_url($link->url, PHP_URL_PATH);
        $browser->get($path);
        self::assertSame(303, $browser->post($path)->getStatusCode());

        $browser->get($idp->authorize($start, ['sub' => 'sub-owner']));

        self::assertSame([], $this->identities($app)->forUser($partner->id));
        self::assertSame([], $this->identities($app)->forUser($this->owner($app)->id));
    }

    public function testUnlinkIsRefusedWhileItIsTheOnlyWayIn(): void
    {
        [$app, $idp] = $this->ssoApp(['OIDC_AUTO_CREATE' => 'true']);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $browser = new TestBrowser($app);
        $this->ssoSignIn($browser, $idp, ['sub' => 'sub-new', 'preferred_username' => 'kim']);
        $kim = $this->users($app)->findByUsername('kim');
        self::assertNotNull($kim);
        $identity = $this->identities($app)->forUser($kim->id)[0];

        $settings = self::body($browser->get('/settings'));
        self::assertStringContainsString('Set a password', $settings);
        self::assertStringNotContainsString('current_password', $settings, 'there is no current password');
        self::assertStringContainsString('This is your only way to sign in.', $settings);
        $refused = $browser->post('/settings/sso/' . $identity->id . '/unlink');
        self::assertStringContainsString('only way to sign in, so it stays linked', self::body($browser->follow($refused)));
        self::assertCount(1, $this->identities($app)->forUser($kim->id));

        $set = $browser->post('/settings/password', [
            'new_password' => 'a brand new passphrase',
            'new_password_confirm' => 'a brand new passphrase',
        ]);
        self::assertSame(303, $set->getStatusCode());
        self::assertTrue($this->users($app)->findByUsername('kim')?->hasPassword());
        $browser->get('/settings');
        $unlinked = $browser->post('/settings/sso/' . $identity->id . '/unlink');
        self::assertStringContainsString('is unlinked', self::body($browser->follow($unlinked)));
        self::assertSame(303, $this->browserForPassword($app, 'kim', 'a brand new passphrase'));
    }

    public function testAnotherUsersIdentityCannotBeUnlinked(): void
    {
        [$app] = $this->ssoApp();
        $browser = $this->signedIn($app);
        $member = $this->createMember($app);
        $theirs = $this->link($app, $member, 'sub-member');

        $browser->get('/settings');
        $browser->post('/settings/sso/' . $theirs->id . '/unlink');

        self::assertCount(1, $this->identities($app)->forUser($member->id));
    }

    public function testSettingsUsersShowsSignInMethodsAndTheRedirectUri(): void
    {
        [$app] = $this->ssoApp(['OIDC_LOGOUT' => 'true']);
        $admin = $this->signedIn($app);
        $owner = $this->owner($app);
        $member = $this->createMember($app);
        $this->link($app, $owner, 'sub-owner');
        $memberIdentity = $this->link($app, $member, 'sub-member');
        $this->connection($app)->update('users', ['password_hash' => null], ['id' => $member->id]);

        $page = self::body($admin->get('/settings/users'));
        self::assertStringContainsString('Single sign-on with Authentik is on.', $page);
        self::assertStringContainsString('value="http://localhost:8080/auth/oidc/callback"', $page);
        self::assertStringContainsString('value="http://localhost:8080/login"', $page, 'the post-logout redirect too');
        self::assertStringContainsString('Signs in with: Password, Authentik', $page);
        self::assertStringContainsString('Signs in with: Authentik', $page);

        $refused = $admin->post('/settings/users/' . $member->id . '/identities/' . $memberIdentity->id . '/remove');
        self::assertStringContainsString('only way to sign in, so it stays', self::body($admin->follow($refused)));
        $ownerIdentity = $this->identities($app)->forUser($owner->id)[0];
        $removed = $admin->post('/settings/users/' . $owner->id . '/identities/' . $ownerIdentity->id . '/remove');
        self::assertStringContainsString('account was removed', self::body($admin->follow($removed)));
        $mismatch = $admin->post('/settings/users/' . $owner->id . '/identities/' . $memberIdentity->id . '/remove');
        self::assertSame(303, $mismatch->getStatusCode());
        self::assertCount(1, $this->identities($app)->forUser($member->id), 'only the named user\'s identity');

        $partner = $this->browserForPassword($app, 'partner', self::PASSWORD);
        self::assertSame(422, $partner, 'the member has no password: password sign-in is refused');
    }

    public function testWithoutSsoSettingsUsersSaysHowToSetItUp(): void
    {
        $app = $this->createApp();
        $page = self::body($this->signedIn($app)->get('/settings/users'));

        self::assertStringContainsString('Single sign-on is not set up.', $page);
        self::assertStringContainsString('/auth/oidc/callback', $page);
    }

    public function testSignOutAtTheProviderWhenAsked(): void
    {
        [$app, $idp] = $this->ssoApp(['OIDC_LOGOUT' => 'true']);
        $this->resetDatabase($app);
        $this->link($app, $this->createOwner($app), 'sub-owner');

        $browser = new TestBrowser($app);
        $this->ssoSignIn($browser, $idp, ['sub' => 'sub-owner']);
        $browser->get('/settings');
        $logout = $browser->post('/logout');

        self::assertSame(303, $logout->getStatusCode());
        $location = $logout->getHeaderLine('Location');
        self::assertStringStartsWith(FakeIdentityProvider::ISSUER . '/protocol/openid-connect/logout?', $location);
        $query = FakeIdentityProvider::query($location);
        self::assertSame('http://localhost:8080/login', $query['post_logout_redirect_uri']);
        self::assertSame(FakeIdentityProvider::CLIENT_ID, $query['client_id']);
        self::assertSame(3, count(explode('.', $query['id_token_hint'])), 'the ID token from the sign-in');
        self::assertSame(303, $browser->get('/')->getStatusCode(), 'signed out here as well');

        $password = $this->browserFor($app, 'owner');
        $password->get('/settings');
        self::assertSame('/login', $password->post('/logout')->getHeaderLine('Location'), 'a password session signs out locally');
    }

    public function testWithoutAnEndSessionEndpointOrOidcLogoutSignOutIsLocal(): void
    {
        [$app, $idp] = $this->ssoApp(['OIDC_LOGOUT' => 'true']);
        $idp->discoveryOverrides = ['end_session_endpoint' => null];
        $this->resetDatabase($app);
        $this->link($app, $this->createOwner($app), 'sub-owner');
        $browser = new TestBrowser($app);
        $this->ssoSignIn($browser, $idp, ['sub' => 'sub-owner']);
        $browser->get('/settings');
        self::assertSame('/login', $browser->post('/logout')->getHeaderLine('Location'));

        // Same database, a second app with OIDC_LOGOUT unset; the owner is still linked.
        [$off, $idp] = $this->ssoApp();
        $browser = new TestBrowser($off);
        $this->ssoSignIn($browser, $idp, ['sub' => 'sub-owner']);
        $browser->get('/settings');
        self::assertSame('/login', $browser->post('/logout')->getHeaderLine('Location'), 'OIDC_LOGOUT is off by default');
    }

    public function testItWorksUnderABasePath(): void
    {
        [$app, $idp] = $this->ssoApp(['APP_BASE_PATH' => '/logbook', 'APP_URL' => 'https://cars.example.org']);
        $this->resetDatabase($app);
        $this->link($app, $this->createOwner($app), 'sub-owner');

        $browser = new TestBrowser($app);
        self::assertStringContainsString('href="/logbook/auth/oidc/start"', self::body($browser->get('/logbook/login')));
        $start = $browser->get('/logbook/auth/oidc/start?next=' . rawurlencode('/logbook/garage'));
        $redirect = FakeIdentityProvider::query($start->getHeaderLine('Location'))['redirect_uri'];
        self::assertSame('https://cars.example.org/logbook/auth/oidc/callback', $redirect);
        $callback = $idp->authorize($start->getHeaderLine('Location'), ['sub' => 'sub-owner']);
        self::assertStringStartsWith('/logbook/auth/oidc/callback?', $callback);

        self::assertSame('/logbook/garage', $browser->get($callback)->getHeaderLine('Location'));
        self::assertSame(200, $browser->get('/logbook/garage')->getStatusCode());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function link(App $app, User $user, string $subject): UserIdentity
    {
        return $this->identities($app)
            ->insert($user->id, UserIdentity::OIDC, FakeIdentityProvider::ISSUER, $subject, new \DateTimeImmutable());
    }

    /**
     * @param App<ContainerInterface> $app
     * @return int the sign-in POST's status (303 signed in, 422 refused)
     */
    private function browserForPassword(App $app, string $username, string $password): int
    {
        $browser = new TestBrowser($app);
        $browser->get('/login');
        $response = $browser->post('/login', ['username' => $username, 'password' => $password]);

        return $response->getStatusCode() === 303 && $browser->get('/')->getStatusCode() === 200 ? 303 : 422;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function identities(App $app): UserIdentityRepository
    {
        return $this->service($app, UserIdentityRepository::class);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function users(App $app): UserRepository
    {
        return $this->service($app, UserRepository::class);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function owner(App $app): User
    {
        $owner = $this->users($app)->findByUsername('owner');
        self::assertNotNull($owner);

        return $owner;
    }
}
