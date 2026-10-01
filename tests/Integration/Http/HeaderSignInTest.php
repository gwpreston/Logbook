<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\User\UserIdentity;
use Logbook\Repository\UserIdentityRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\HeaderSignIn;
use Logbook\Tests\Support\TestBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Header sign-in behind a forward-auth proxy (spec.md §7.9, Phase 23.2):
 * trust by connecting address only, finding the user, the session
 * following the header, sign-out, and the paths it never touches.
 */
final class HeaderSignInTest extends AppTestCase
{
    use HeaderSignIn;

    protected function tearDown(): void
    {
        $this->removeThrottleDirs();
        parent::tearDown();
    }

    // --- Trust ----------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function trustedAddresses(): iterable
    {
        yield 'IPv4 in a CIDR range' => ['10.0.0.5'];
        yield 'one IPv4 address' => ['198.51.100.7'];
        yield 'IPv4-mapped IPv6' => ['::ffff:10.0.0.200'];
        yield 'IPv6 in a range' => ['fd00:1::abcd'];
    }

    #[DataProvider('trustedAddresses')]
    public function testAHeaderFromATrustedProxySignsIn(string $address): void
    {
        [$app] = $this->proxyApp();
        $owner = $this->createOwner($app);
        $browser = $this->viaProxy($app, ['Remote-User' => 'Owner'], $address);

        $first = $browser->get('/garage?view=list');
        self::assertSame(303, $first->getStatusCode(), 'signed in, then the same page again');
        self::assertSame('/garage?view=list', $first->getHeaderLine('Location'));
        self::assertNotNull($browser->sessionCookie());
        self::assertSame(200, $browser->get('/garage?view=list')->getStatusCode());

        $identity = $this->identities($app)->findBySubject(UserIdentity::PROXY, 'remote-user', 'owner');
        self::assertSame($owner->id, $identity?->userId, 'linked by username (the default), value lower-cased');
    }

    public function testFromAnUntrustedAddressTheHeaderIsIgnoredAndLoggedOncePerHour(): void
    {
        [$app, $log, $clock] = $this->proxyApp();
        $this->createOwner($app);
        $browser = $this->viaProxy($app, ['Remote-User' => 'owner'], '203.0.113.9');

        self::assertSame('/login?next=%2F', $browser->get('/')->getHeaderLine('Location'), 'normal sign-in');
        $browser->get('/');
        $browser->get('/login');
        self::assertSame(
            ['Header Remote-User from 203.0.113.9 ignored: not a trusted proxy'],
            self::logLines($log, 'not a trusted proxy'),
        );
        self::assertStringNotContainsString('data-proxy-notice', self::body($browser->get('/login')));

        $clock->set($clock->now()->modify('+61 minutes'));
        $browser->get('/');
        self::assertCount(2, self::logLines($log, 'not a trusted proxy'), 'again after an hour');
        self::assertSame([], $this->identities($app)->forUser($this->owner($app)->id));
    }

    public function testForwardingHeadersNamingATrustedAddressAreIgnored(): void
    {
        [$app] = $this->proxyApp();
        $this->createOwner($app);
        $browser = $this->viaProxy($app, [
            'Remote-User' => 'owner',
            'X-Forwarded-For' => self::PROXY_ADDRESS,
            'X-Real-IP' => self::PROXY_ADDRESS,
            'Forwarded' => 'for=' . self::PROXY_ADDRESS,
        ], '203.0.113.9');

        self::assertStringStartsWith('/login', $browser->get('/')->getHeaderLine('Location'));
    }

    public function testAHeaderSentTwiceOrNotOneUsernameIsRefused(): void
    {
        [$app, $log] = $this->proxyApp();
        $this->createOwner($app);

        $twice = $this->viaProxy($app, [])->get('/', ['Remote-User' => 'owner, admin']);
        self::assertStringStartsWith('/login', $twice->getHeaderLine('Location'));
        self::assertCount(1, self::logLines($log, 'refused: the value is not one username'));
        $long = $this->viaProxy($app, [])->get('/', ['Remote-User' => str_repeat('a', 256)]);
        self::assertStringStartsWith('/login', $long->getHeaderLine('Location'));
        self::assertCount(1, self::logLines($log, 'Header Remote-User from 10.0.0.5 refused'), 'once per address per hour');
    }

    public function testTheUnderscoreSpellingIsNeverReadAsTheHeader(): void
    {
        [$app] = $this->proxyApp();
        $this->createOwner($app);
        // slim/psr7 folds Remote_User into Remote-User; the server's HTTP_* variables don't have it.
        $browser = $this->viaProxy($app, ['Remote_User' => 'owner']);

        self::assertStringStartsWith('/login', self::location($browser));
        $psr7 = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/')
            ->withHeader('Remote_User', 'owner');
        self::assertSame('owner', $psr7->getHeaderLine('Remote-User'), 'why PSR-7\'s header list is not read');
        self::assertStringContainsString('data-proxy-notice="missing"', self::body($browser->get('/login')));
    }

    public function testTheCgiRemoteUserVariableIsNeverRead(): void
    {
        [$app] = $this->proxyApp();
        $this->createOwner($app);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/', [
            'REMOTE_ADDR' => self::PROXY_ADDRESS,
            'REMOTE_USER' => 'owner',
        ]);

        self::assertStringStartsWith('/login', $app->handle($request)->getHeaderLine('Location'));
    }

    public function testAListedProxyWithoutTheHeaderGetsANoticeOnTheSignInPage(): void
    {
        [$app] = $this->proxyApp();
        $this->createOwner($app);

        $page = self::body($this->viaProxy($app, [])->get('/login'));
        self::assertStringContainsString('data-proxy-notice="missing"', $page);
        self::assertStringContainsString('Your sign-in proxy didn&#039;t send a user. Check its configuration.', $page);
        self::assertStringContainsString('name="password"', $page, 'besides the usual methods');
        $elsewhere = self::body((new TestBrowser($app))->get('/login'));
        self::assertStringNotContainsString('data-proxy-notice', $elsewhere, 'not from elsewhere');
    }

    // --- Finding the user ----------------------------------------------------------

    public function testALinkedIdentityWinsOverTheUsername(): void
    {
        [$app] = $this->proxyApp(['AUTH_PROXY_LINK' => 'identity']);
        $this->createOwner($app);
        $partner = $this->createMember($app);
        $this->identities($app)->insert($partner->id, UserIdentity::PROXY, 'remote-user', 'owner', new \DateTimeImmutable());

        $browser = $this->viaProxy($app, ['Remote-User' => 'owner']);
        $browser->get('/');
        self::assertStringContainsString('Sam Partner', self::body($browser->get('/settings')));
    }

    public function testIdentityModeRefusesAnUnlinkedUser(): void
    {
        [$app] = $this->proxyApp(['AUTH_PROXY_LINK' => 'identity']);
        $owner = $this->createOwner($app);
        $browser = $this->viaProxy($app, ['Remote-User' => 'owner']);

        $response = $browser->get('/');
        self::assertSame('/login?next=%2F', $response->getHeaderLine('Location'));
        $page = self::body($browser->follow($response));
        self::assertStringContainsString('data-proxy-notice="not_linked"', $page);
        self::assertStringContainsString('Your sign-in proxy&#039;s account owner isn&#039;t linked to Logbook.', $page);
        self::assertSame([], $this->identities($app)->forUser($owner->id));
    }

    public function testUsernameLinkingSkipsAUserWhoAlreadyHasAProxyIdentity(): void
    {
        [$app] = $this->proxyApp();
        $owner = $this->createOwner($app);
        $this->identities($app)->insert($owner->id, UserIdentity::PROXY, 'remote-user', 'pat', new \DateTimeImmutable());

        self::assertStringStartsWith('/login', self::location($this->viaProxy($app, ['Remote-User' => 'owner'])));
        self::assertCount(1, $this->identities($app)->forUser($owner->id));
    }

    public function testANewMemberIsCreatedWithNameAndEmailAndWelcomed(): void
    {
        [$app] = $this->proxyApp([
            'AUTH_PROXY_AUTO_CREATE' => 'true',
            'AUTH_PROXY_NAME_HEADER' => 'Remote-Name',
            'AUTH_PROXY_EMAIL_HEADER' => 'Remote-Email',
        ]);
        $this->createOwner($app);
        $browser = $this->viaProxy($app, [
            'Remote-User' => 'Robin',
            'Remote-Name' => 'Robin Driver',
            'Remote-Email' => 'robin@example.com',
        ]);

        $response = $browser->get('/garage');
        self::assertSame('/welcome', $response->getHeaderLine('Location'));
        $robin = $this->service($app, UserRepository::class)->findByUsername('robin');
        self::assertNotNull($robin);
        self::assertSame('Robin Driver', $robin->displayName);
        self::assertFalse($robin->isAdmin);
        self::assertFalse($robin->hasPassword());
        $notifications = $this->service($app, ReminderSettingsStore::class)->notificationPreferences($robin->id);
        self::assertSame('robin@example.com', $notifications->email, 'their reminder email address');
        self::assertSame(200, $browser->get('/welcome')->getStatusCode());
        self::assertSame('/garage', $browser->post('/welcome', ['skip' => '1'])->getHeaderLine('Location'));
    }

    public function testAllowedGroupsRefuseEveryoneElse(): void
    {
        [$app] = $this->proxyApp([
            'AUTH_PROXY_GROUPS_HEADER' => 'Remote-Groups',
            'AUTH_PROXY_ALLOWED_GROUPS' => 'family, logbook',
        ]);
        $this->createOwner($app);

        $outsider = $this->viaProxy($app, ['Remote-User' => 'owner', 'Remote-Groups' => 'other,people']);
        self::assertStringStartsWith('/login', $outsider->get('/')->getHeaderLine('Location'));
        $member = $this->viaProxy($app, ['Remote-User' => 'owner', 'Remote-Groups' => 'people, logbook']);
        self::assertSame('/', $member->get('/')->getHeaderLine('Location'));
        self::assertSame(200, $member->get('/')->getStatusCode());
        $authentik = $this->viaProxy($app, ['Remote-User' => 'owner', 'Remote-Groups' => 'people|logbook'], '198.51.100.7');
        self::assertSame('/', self::location($authentik), 'Authentik separates groups with "|"');
    }

    public function testAdminFollowsTheGroupsButTheLastAdminStays(): void
    {
        [$app] = $this->proxyApp(['AUTH_PROXY_GROUPS_HEADER' => 'Remote-Groups', 'AUTH_PROXY_ADMIN_GROUPS' => 'logbook-admins']);
        $owner = $this->createOwner($app);
        $partner = $this->createMember($app);
        $users = $this->service($app, UserRepository::class);

        $this->viaProxy($app, ['Remote-User' => 'partner', 'Remote-Groups' => 'logbook-admins'])->get('/');
        self::assertTrue($users->find($partner->id)?->isAdmin);
        $this->viaProxy($app, ['Remote-User' => 'partner', 'Remote-Groups' => 'logbook'])->get('/');
        self::assertFalse($users->find($partner->id)->isAdmin, 'both ways');

        $this->viaProxy($app, ['Remote-User' => 'owner', 'Remote-Groups' => 'logbook'])->get('/');
        self::assertTrue($users->find($owner->id)?->isAdmin, 'never the last active admin');
    }

    public function testADisabledUserIsRefused(): void
    {
        [$app] = $this->proxyApp();
        $this->createOwner($app);
        $partner = $this->createMember($app);
        $now = new \DateTimeImmutable();
        $this->service($app, UserRepository::class)->setDisabledAt($partner->id, $now, $now);
        $browser = $this->viaProxy($app, ['Remote-User' => 'partner']);

        $response = $browser->get('/');
        self::assertStringStartsWith('/login', $response->getHeaderLine('Location'));
        $page = self::body($browser->follow($response));
        self::assertStringContainsString('Sign-in through your proxy didn&#039;t work. Ask an admin.', $page);
    }

    public function testNothingHappensBeforeSetup(): void
    {
        [$app] = $this->proxyApp(['AUTH_PROXY_AUTO_CREATE' => 'true']);

        self::assertSame('/setup', $this->viaProxy($app, ['Remote-User' => 'robin'])->get('/')->getHeaderLine('Location'));
        self::assertFalse($this->service($app, UserRepository::class)->exists());
    }

    // --- The session follows the header ---------------------------------------------

    public function testAnotherUsersHeaderReplacesTheSessionUnderANewId(): void
    {
        [$app] = $this->proxyApp();
        $this->createOwner($app);
        $this->createMember($app);
        $browser = $this->viaProxy($app, ['Remote-User' => 'owner']);
        $browser->get('/');
        $ownerSession = $browser->sessionCookie();

        $browser->sending(['Remote-User' => 'partner']);
        self::assertSame('/settings', $browser->get('/settings')->getHeaderLine('Location'));
        self::assertNotSame($ownerSession, $browser->sessionCookie(), 'a new session id (fixation)');
        self::assertStringContainsString('Sam Partner', self::body($browser->get('/settings')));
    }

    public function testAMissingHeaderEndsAHeaderBasedSession(): void
    {
        [$app] = $this->proxyApp();
        $this->createOwner($app);
        $browser = $this->viaProxy($app, ['Remote-User' => 'owner']);
        $browser->get('/');
        self::assertSame(200, $browser->get('/')->getStatusCode());

        $browser->sending(['Remote-User' => '']);
        self::assertSame('/garage', $browser->get('/garage')->getHeaderLine('Location'), 'the session ends');
        self::assertNull($browser->sessionCookie());
        self::assertStringStartsWith('/login', $browser->get('/garage')->getHeaderLine('Location'));
    }

    public function testAHeaderBasedSessionEndsWhenTheProxyIsBypassed(): void
    {
        [$app] = $this->proxyApp();
        $this->createOwner($app);
        $browser = $this->viaProxy($app, ['Remote-User' => 'owner']);
        $browser->get('/');

        $browser->from('203.0.113.9');
        $browser->get('/');
        self::assertStringStartsWith('/login', $browser->get('/')->getHeaderLine('Location'));
    }

    public function testAPasswordSessionSurvivesNoHeaderAndAnUnlinkedOne(): void
    {
        [$app] = $this->proxyApp(['AUTH_PROXY_LINK' => 'identity']);
        $this->createOwner($app);
        $browser = $this->browserFor($app, 'owner')->from(self::PROXY_ADDRESS);
        $session = $browser->sessionCookie();

        self::assertSame(200, $browser->get('/')->getStatusCode(), 'LAN access without the proxy header');
        $browser->sending(['Remote-User' => 'stranger']);
        $page = self::body($browser->get('/'));
        self::assertStringContainsString('data-proxy-link-offer', $page, 'kept, and offered the link');
        self::assertStringContainsString('Your sign-in proxy says you are stranger', $page);
        self::assertSame($session, $browser->sessionCookie());
    }

    public function testAHeaderForTheSameUserKeepsAPasswordSession(): void
    {
        [$app] = $this->proxyApp();
        $this->createOwner($app);
        $browser = $this->browserFor($app, 'owner')->from(self::PROXY_ADDRESS)->sending(['Remote-User' => 'owner']);
        $session = $browser->sessionCookie();

        self::assertSame(200, $browser->get('/')->getStatusCode());
        self::assertSame($session, $browser->sessionCookie());
        $browser->sending(['Remote-User' => '']);
        self::assertSame(200, $browser->get('/')->getStatusCode(), 'still a password session');
    }

    public function testAPasswordSessionIsReplacedByAnotherLinkedUser(): void
    {
        [$app] = $this->proxyApp();
        $this->createOwner($app);
        $this->createMember($app);
        $browser = $this->browserFor($app, 'owner')->from(self::PROXY_ADDRESS);
        $browser->get('/');
        $session = $browser->sessionCookie();

        $browser->sending(['Remote-User' => 'partner']);
        $post = $browser->post('/dashboard/layout', ['reset' => '1']);
        self::assertSame('/', $post->getHeaderLine('Location'), 'a post that brings a switch goes home, unapplied');
        self::assertNotSame($session, $browser->sessionCookie());
        self::assertStringContainsString('Sam Partner', self::body($browser->get('/settings')));
    }

    // --- Linking while signed in --------------------------------------------------

    public function testTheBannerLinksTheProxyAccount(): void
    {
        [$app] = $this->proxyApp(['AUTH_PROXY_LINK' => 'identity']);
        $owner = $this->createOwner($app);
        $browser = $this->browserFor($app, 'owner')->from(self::PROXY_ADDRESS)->sending(['Remote-User' => 'pat']);
        $browser->get('/garage');

        $response = $browser->post('/auth/proxy/link', ['return' => '/garage']);
        self::assertSame('/garage', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Your proxy account pat is linked.', self::body($browser->follow($response)));
        self::assertSame('pat', $this->identities($app)->forUser($owner->id)[0]->subject ?? null);
        self::assertStringNotContainsString('data-proxy-link-offer', self::body($browser->get('/garage')));
        $settings = self::body($browser->get('/settings'));
        self::assertStringContainsString('Proxy account pat linked', $settings);

        // From now on the proxy alone signs them in.
        $fresh = $this->viaProxy($app, ['Remote-User' => 'pat']);
        $fresh->get('/');
        self::assertSame(200, $fresh->get('/')->getStatusCode());
    }

    public function testNoBannerForAUserWithAProxyIdentityOrOutsideTheGroups(): void
    {
        [$app] = $this->proxyApp([
            'AUTH_PROXY_LINK' => 'identity',
            'AUTH_PROXY_GROUPS_HEADER' => 'Remote-Groups',
            'AUTH_PROXY_ALLOWED_GROUPS' => 'logbook',
        ]);
        $owner = $this->createOwner($app);
        $browser = $this->browserFor($app, 'owner')->from(self::PROXY_ADDRESS);

        $browser->sending(['Remote-User' => 'pat', 'Remote-Groups' => 'other']);
        self::assertStringNotContainsString('data-proxy-link-offer', self::body($browser->get('/')));
        self::assertStringContainsString(
            'That proxy account isn&#039;t in a group allowed to use Logbook.',
            self::body($browser->follow($browser->post('/auth/proxy/link'))),
        );

        $this->identities($app)->insert($owner->id, UserIdentity::PROXY, 'remote-user', 'old-name', new \DateTimeImmutable());
        $browser->sending(['Remote-Groups' => 'logbook']);
        self::assertStringNotContainsString('data-proxy-link-offer', self::body($browser->get('/')));
    }

    public function testLinkingNeedsTheHeaderOnThatRequest(): void
    {
        [$app] = $this->proxyApp(['AUTH_PROXY_LINK' => 'identity']);
        $owner = $this->createOwner($app);
        $browser = $this->browserFor($app, 'owner');

        $response = $browser->post('/auth/proxy/link', [], [], true, ['Remote-User' => 'pat']);
        $page = self::body($browser->follow($response));
        self::assertStringContainsString('didn&#039;t send an account with that request', $page, 'not from a trusted proxy');
        self::assertSame([], $this->identities($app)->forUser($owner->id));
    }

    public function testAdminsSeeAndRemoveTheProxyMethod(): void
    {
        [$app] = $this->proxyApp();
        $owner = $this->createOwner($app);
        $partner = $this->createMember($app);
        $identity = $this->identities($app)
            ->insert($partner->id, UserIdentity::PROXY, 'remote-user', 'partner', new \DateTimeImmutable());
        $browser = $this->browserFor($app, 'owner');

        $page = self::body($browser->get('/settings/users'));
        self::assertStringContainsString('Password, Proxy', $page);
        self::assertStringContainsString('Header sign-in is on: the user comes from the Remote-User header.', $page);
        self::assertStringContainsString('Trusted proxy addresses: 10.0.0.0/24, fd00:1::/64, 198.51.100.7/32.', $page);
        $removed = $browser->post('/settings/users/' . $partner->id . '/identities/' . $identity->id . '/remove');
        self::assertStringContainsString('Proxy account was removed', self::body($browser->follow($removed)));
        self::assertSame([], $this->identities($app)->forUser($partner->id));
        self::assertSame($owner->id, $this->owner($app)->id);
    }

    // --- Sign-out ------------------------------------------------------------------

    public function testSignOutWithoutALogoutUrlExplainsTheProxy(): void
    {
        [$app] = $this->proxyApp();
        $this->createOwner($app);
        $browser = $this->viaProxy($app, ['Remote-User' => 'owner']);
        $browser->get('/');
        $browser->get('/settings');

        $response = $browser->post('/logout');
        self::assertSame(200, $response->getStatusCode(), 'a page, not a redirect into signing straight back in');
        self::assertStringContainsString('your proxy signs you straight back in', self::body($response));
        self::assertStringContainsString('data-proxy-signed-out', self::body($response));
    }

    public function testSignOutGoesToTheProxysLogoutUrl(): void
    {
        [$app] = $this->proxyApp(['AUTH_PROXY_LOGOUT_URL' => 'https://auth.example.com/logout?rd=https://logbook.example.com/']);
        $this->createOwner($app);
        $browser = $this->viaProxy($app, ['Remote-User' => 'owner']);
        $browser->get('/');
        $browser->get('/settings');

        $response = $browser->post('/logout');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://auth.example.com/logout?rd=https://logbook.example.com/', $response->getHeaderLine('Location'));

        $password = $this->browserFor($app, 'owner');
        $password->get('/settings');
        $signedOut = $password->post('/logout');
        self::assertSame('/login', $signedOut->getHeaderLine('Location'), 'a password session signs out as before');
    }

    // --- Never elsewhere ---------------------------------------------------------------

    public function testNeverActiveOnTheApiCalendarFeedHealthOrPwaFiles(): void
    {
        [$app] = $this->proxyApp();
        $this->createOwner($app);
        $browser = $this->viaProxy($app, ['Remote-User' => 'owner']);

        self::assertSame(401, $browser->get('/api/v1/me')->getStatusCode(), 'the API keeps its keys');
        self::assertSame(404, $browser->get('/calendar/1-' . str_repeat('a', 64) . '.ics')->getStatusCode());
        self::assertSame(200, $browser->get('/health')->getStatusCode());
        self::assertSame(200, $browser->get('/manifest.webmanifest')->getStatusCode());
        self::assertNull($browser->sessionCookie(), 'none of them signed anyone in');
        self::assertSame([], $this->identities($app)->forUser($this->owner($app)->id));
    }

    public function testWorksUnderABasePath(): void
    {
        [$app] = $this->proxyApp(['APP_BASE_PATH' => '/logbook']);
        $this->createOwner($app);
        $browser = $this->viaProxy($app, ['Remote-User' => 'owner']);

        self::assertSame('/logbook/garage', $browser->get('/logbook/garage')->getHeaderLine('Location'));
        self::assertSame(200, $browser->get('/logbook/garage')->getStatusCode());
        $browser->get('/logbook/settings');
        self::assertStringContainsString('action="/logbook/logout"', self::body($browser->get('/logbook/settings')));

        $browser->sending(['Remote-User' => '']);
        self::assertSame('/logbook/garage', $browser->get('/logbook/garage')->getHeaderLine('Location'));
        self::assertSame('/logbook/login?next=%2Flogbook%2Fgarage', $browser->get('/logbook/garage')->getHeaderLine('Location'));
    }

    // --- Signed JWT -----------------------------------------------------------------------

    public function testAValidJwtSignsInFromAnyAddressWithoutATrustedList(): void
    {
        [$app, , $clock] = $this->jwtApp(['AUTH_PROXY_GROUPS_HEADER' => '']);
        $owner = $this->createOwner($app);
        $browser = $this->viaProxy($app, ['X-authentik-jwt' => self::proxyJwt($clock)], '203.0.113.9');

        $browser->get('/');
        self::assertSame(200, $browser->get('/')->getStatusCode());
        $identity = $this->identities($app)->findBySubject(UserIdentity::PROXY, self::JWT_ISSUER, 'c0ffee-owner');
        self::assertSame($owner->id, $identity?->userId, 'iss and sub, linked by preferred_username');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function badTokens(): iterable
    {
        yield 'another secret' => [[], 'another-secret-that-is-at-least-32-characters-long', 'HS256'];
        yield 'HS512' => [[], self::JWT_SECRET . self::JWT_SECRET, 'HS512'];
        yield 'another issuer' => [['iss' => 'https://evil.example/'], self::JWT_SECRET, 'HS256'];
        yield 'another audience' => [['aud' => 'grafana'], self::JWT_SECRET, 'HS256'];
        yield 'expired' => [['exp' => 1790000000], self::JWT_SECRET, 'HS256'];
        yield 'issued in the future' => [['iat' => 1890000000], self::JWT_SECRET, 'HS256'];
        yield 'no subject' => [['sub' => ''], self::JWT_SECRET, 'HS256'];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('badTokens')]
    public function testABadJwtSignsNobodyIn(array $claims, string $secret, string $alg): void
    {
        [$app, $log, $clock] = $this->jwtApp();
        $this->createOwner($app);
        $browser = $this->viaProxy($app, ['X-authentik-jwt' => self::proxyJwt($clock, $claims, $secret, $alg)]);

        self::assertStringStartsWith('/login', $browser->get('/')->getHeaderLine('Location'));
        self::assertCount(1, self::logLines($log, 'Header X-authentik-jwt from 10.0.0.5 refused: the JWT'));
    }

    public function testAnUnsignedJwtIsRefused(): void
    {
        [$app, $log, $clock] = $this->jwtApp();
        $this->createOwner($app);
        [, $payload] = explode('.', self::proxyJwt($clock));
        $none = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=') . '.' . $payload . '.';

        self::assertStringStartsWith('/login', self::location($this->viaProxy($app, ['X-authentik-jwt' => $none])));
        self::assertCount(1, self::logLines($log, 'only HS256 is accepted'));
    }

    public function testAnRs256JwtIsRefused(): void
    {
        [$app, $log, $clock] = $this->jwtApp();
        $this->createOwner($app);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $pem = '';
        openssl_pkey_export($key, $pem);
        self::assertIsString($pem);
        $token = self::proxyJwt($clock, [], $pem, 'RS256');

        self::assertStringStartsWith('/login', self::location($this->viaProxy($app, ['X-authentik-jwt' => $token])));
        self::assertCount(1, self::logLines($log, 'the JWT uses the algorithm "RS256"; only HS256 is accepted.'));
    }

    public function testWithATrustedListTheJwtAddressIsStillChecked(): void
    {
        [$app, , $clock] = $this->jwtApp(['AUTH_PROXY_TRUSTED' => '10.0.0.0/24']);
        $this->createOwner($app);
        $token = self::proxyJwt($clock);

        $elsewhere = $this->viaProxy($app, ['X-authentik-jwt' => $token], '203.0.113.9');
        self::assertStringStartsWith('/login', self::location($elsewhere));
        self::assertSame('/', $this->viaProxy($app, ['X-authentik-jwt' => $token])->get('/')->getHeaderLine('Location'));
    }

    public function testJwtClaimsGiveTheGroupsAndAnExpiredTokenEndsTheSession(): void
    {
        [$app, , $clock] = $this->jwtApp(['AUTH_PROXY_ADMIN_GROUPS' => 'logbook-admins']);
        $this->createOwner($app);
        $partner = $this->createMember($app);
        $token = self::proxyJwt($clock, [
            'sub' => 'c0ffee-partner',
            'preferred_username' => 'partner',
            'groups' => ['logbook-admins'],
            'exp' => $clock->now()->getTimestamp() + 600,
        ]);
        $browser = $this->viaProxy($app, ['X-authentik-jwt' => $token, 'X-authentik-username' => 'owner']);

        $browser->get('/');
        self::assertTrue($this->service($app, UserRepository::class)->find($partner->id)?->isAdmin, 'groups from the claims');
        $settings = self::body($browser->get('/settings'));
        self::assertStringContainsString('Sam Partner', $settings, 'never the plain username header');

        $clock->set($clock->now()->modify('+11 minutes'));
        self::assertSame('/', $browser->get('/')->getHeaderLine('Location'), 'expired: the session ends');
        self::assertStringStartsWith('/login', $browser->get('/')->getHeaderLine('Location'));
    }

    // --- Helpers ---------------------------------------------------------------------------

    private static function location(TestBrowser $browser, string $path = '/'): string
    {
        return $browser->get($path)->getHeaderLine('Location');
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
    private function owner(App $app): \Logbook\Domain\User\User
    {
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);

        return $owner;
    }
}
