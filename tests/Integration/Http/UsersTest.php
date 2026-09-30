<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateInterval;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\User\User;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Api\ApiKeyService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Users and invitations (spec.md §7.9): an admin invites, the link is used
 * once within seven days; admins, disabling and deleting keep an active
 * admin and never touch other people's data.
 */
final class UsersTest extends AppTestCase
{
    use CostFixtures;

    public function testAnInvitedUserSignsUpOnceAndSeesOnlyTheirOwn(): void
    {
        $app = $this->createApp();
        $admin = $this->signedIn($app);
        $golf = $this->vehicle($app);

        $admin->get('/settings/users');
        $page = $admin->post('/settings/users', ['username' => 'Partner', 'display_name' => 'Sam Partner']);
        self::assertSame(200, $page->getStatusCode());
        self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
        $link = self::link(self::body($page));
        self::assertStringContainsString('/invite/', $link);

        $taken = $admin->post('/settings/users', ['username' => 'partner']);
        self::assertSame(422, $taken->getStatusCode(), 'an open invite holds the username');
        self::assertStringContainsString('That username is taken', self::body($taken));

        $guest = new TestBrowser($app);
        $path = (string) parse_url($link, PHP_URL_PATH);
        $form = $guest->get($path);
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('You were invited as', self::body($form));
        $mismatch = $guest->post($path, self::signUp(['password_confirm' => 'something else']));
        self::assertSame(422, $mismatch->getStatusCode());

        $done = $guest->post($path, self::signUp());
        self::assertSame(303, $done->getStatusCode());
        $home = self::body($guest->follow($done));
        self::assertStringContainsString('Welcome, Sam Partner.', $home);
        $partner = $this->service($app, UserRepository::class)->findByUsername('partner');
        self::assertNotNull($partner);
        self::assertFalse($partner->isAdmin, 'not unless the admin ticked it');
        self::assertSame('Europe/Berlin', $partner->preferences->timezone);

        self::assertSame(404, $guest->get('/vehicles/' . $golf->id)->getStatusCode(), 'only their own and shared vehicles');
        self::assertSame(403, $guest->get('/settings/users')->getStatusCode(), 'members do not manage users');
        self::assertSame(403, $guest->get('/settings/backup')->getStatusCode());
        self::assertStringNotContainsString('/settings/users', self::body($guest->get('/settings')));

        $again = new TestBrowser($app);
        self::assertSame(404, $again->get($path)->getStatusCode(), 'a used link is gone');
    }

    public function testLinksExpireAndCanBeRevoked(): void
    {
        $app = $this->createApp();
        $clock = $this->pinClock($app, '2026-09-01T10:00:00Z');
        $admin = $this->signedIn($app);
        $admin->get('/settings/users');
        $expiring = self::path($admin->post('/settings/users', ['username' => 'late']));
        $revoked = self::path($admin->post('/settings/users', ['username' => 'nope']));

        $guest = new TestBrowser($app);
        self::assertSame(200, $guest->get($revoked)->getStatusCode());
        $page = self::body($admin->get('/settings/users'));
        self::assertStringContainsString('Open links', $page);
        preg_match_all('~/settings/users/links/(\d+)/revoke~', $page, $m);
        self::assertCount(2, $m[1]);
        // Newest first: the second link is listed first.
        $admin->post('/settings/users/links/' . $m[1][0] . '/revoke');
        self::assertSame(404, $guest->get($revoked)->getStatusCode(), 'a revoked link is gone');

        $clock->set($clock->now()->add(new DateInterval('P7DT1S')));
        self::assertSame(404, $guest->get($expiring)->getStatusCode(), 'seven days, then gone');
        self::assertSame(404, $guest->get('/invite/' . str_repeat('a', 43))->getStatusCode(), 'an unknown token');
    }

    public function testDisablingEndsSessionsKeysAndSignIn(): void
    {
        $app = $this->createApp();
        $admin = $this->signedIn($app);
        $member = $this->createMember($app);
        $partner = $this->browserFor($app, 'partner');
        $token = $this->service($app, ApiKeyService::class)->create($member, 'Phone', ApiScope::Read)->token;
        $bearer = ['Authorization' => 'Bearer ' . $token];
        self::assertSame(200, $this->get($app, '/api/v1/me', $bearer)->getStatusCode());

        $admin->get('/settings/users');
        $admin->post('/settings/users/' . $member->id . '/disable');
        self::assertNotNull($this->fresh($app, $member->id)->disabledAt);
        self::assertStringContainsString('/login', $partner->get('/garage')->getHeaderLine('Location'), 'their session ended');
        self::assertSame(401, $this->get($app, '/api/v1/me', $bearer)->getStatusCode(), 'keys stop at once');
        $again = new TestBrowser($app);
        $again->get('/login');
        $refused = $again->post('/login', ['username' => 'partner', 'password' => self::PASSWORD]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('That username and password do not match.', self::body($refused), 'as a wrong one');

        $admin->post('/settings/users/' . $member->id . '/enable');
        self::assertSame(303, $again->post('/login', ['username' => 'partner', 'password' => self::PASSWORD])->getStatusCode());
        self::assertSame(200, $this->get($app, '/api/v1/me', $bearer)->getStatusCode());
    }

    public function testThereIsAlwaysAnActiveAdmin(): void
    {
        $app = $this->createApp();
        $admin = $this->signedIn($app);
        $owner = $this->owner($app);
        $admin->get('/settings/users');

        $refused = $admin->follow($admin->post('/settings/users/' . $owner->id . '/member'));
        self::assertStringContainsString('There must always be an active admin', self::body($refused));
        self::assertTrue($this->fresh($app, $owner->id)->isAdmin);
        $admin->post('/settings/users/' . $owner->id . '/disable');
        self::assertNull($this->fresh($app, $owner->id)->disabledAt, 'not oneself');

        $other = $this->createMember($app, 'second', isAdmin: true, displayName: 'Second Admin');
        $admin->post('/settings/users/' . $other->id . '/disable');
        $admin->post('/settings/users/' . $owner->id . '/member');
        self::assertTrue(
            $this->fresh($app, $owner->id)->isAdmin,
            'a disabled admin does not count',
        );
        $admin->post('/settings/users/' . $other->id . '/enable');
        $admin->post('/settings/users/' . $owner->id . '/member');
        self::assertFalse($this->fresh($app, $owner->id)->isAdmin, 'with another active admin');
    }

    public function testAResetLinkEndsSessionsAndSetsANewPassword(): void
    {
        $app = $this->createApp();
        $admin = $this->signedIn($app);
        $member = $this->createMember($app);
        $partner = $this->browserFor($app, 'partner');
        $admin->get('/settings/users');

        $link = self::path($admin->post('/settings/users/' . $member->id . '/reset'));
        self::assertStringContainsString('/login', $partner->get('/garage')->getHeaderLine('Location'), 'their sessions ended');

        $guest = new TestBrowser($app);
        self::assertStringContainsString('Choose a new password', self::body($guest->get($link)));
        $done = $guest->post($link, ['password' => 'a-new-password', 'password_confirm' => 'a-new-password']);
        self::assertSame(303, $done->getStatusCode());
        self::assertSame(200, $guest->get('/garage')->getStatusCode(), 'signed in');

        $fresh = new TestBrowser($app);
        $fresh->get('/login');
        self::assertSame(303, $fresh->post('/login', ['username' => 'partner', 'password' => 'a-new-password'])->getStatusCode());
        self::assertSame(404, (new TestBrowser($app))->get($link)->getStatusCode(), 'once');
    }

    public function testDeletingAUserWhoOwnsVehiclesIsRefusedUntilTheyAreTransferred(): void
    {
        $app = $this->createApp();
        $admin = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $member = $this->createMember($app);
        $shares = $this->service($app, VehicleShareRepository::class);
        $shares->insert($golf->id, $member->id, ShareLevel::Log, false, false, new \DateTimeImmutable('2026-09-01T00:00:00Z'));
        $partner = $this->browserFor($app, 'partner');
        $partner->get('/vehicles/' . $golf->id . '/fuel/new');
        $partner->post('/vehicles/' . $golf->id . '/fuel/new', [
            'filled_at' => '2026-09-20T09:15', 'odometer' => '10500', 'fuel' => 'petrol', 'volume' => '40', 'total' => '61.23',
        ]);
        $theirs = $this->vehicle($app, 'Mini', 'Cooper');
        $this->connection($app)->update('vehicles', ['user_id' => $member->id], ['id' => $theirs->id]);

        $admin->get('/settings/users');
        $page = self::body($admin->get('/settings/users/' . $member->id . '/delete'));
        self::assertStringContainsString('owns 1 vehicle', $page);
        self::assertStringContainsString('Cooper', $page);
        $admin->post('/settings/users/' . $member->id . '/delete');
        self::assertNotNull($this->service($app, UserRepository::class)->find($member->id), 'refused while they own one');

        $admin->post('/settings/users/' . $member->id . '/vehicles/' . $theirs->id . '/transfer', ['username' => 'owner']);
        $admin->post('/settings/users/' . $member->id . '/delete');
        self::assertNull($this->service($app, UserRepository::class)->find($member->id));
        self::assertSame([], $shares->listForVehicle($golf->id), 'their shares go');
        $fills = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id);
        self::assertCount(1, $fills, 'their entries on other people\'s vehicles stay');
        self::assertNull($fills[0]->createdBy, 'as a former user\'s');

        $third = $this->createMember($app, 'third');
        $shares->insert($golf->id, $third->id, ShareLevel::View, false, false, new \DateTimeImmutable());
        self::assertStringContainsString('Added by a former user', self::body($admin->get('/vehicles/' . $golf->id . '/fuel')));
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

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function signUp(array $overrides = []): array
    {
        return $overrides + [
            'display_name' => 'Sam Partner',
            'password' => 'partner-password',
            'password_confirm' => 'partner-password',
            'units' => 'metric',
            'currency' => 'EUR',
            'locale' => 'en_GB',
            'timezone' => 'Europe/Berlin',
        ];
    }

    private static function path(\Psr\Http\Message\ResponseInterface $page): string
    {
        return (string) parse_url(self::link(self::body($page)), PHP_URL_PATH);
    }

    private static function link(string $html): string
    {
        $link = Html::element(Html::document($html), '#f-invite-link')->getAttribute('value');
        self::assertNotNull($link, 'the page shows the one-time link');

        return $link;
    }
}
