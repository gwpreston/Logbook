<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CsrfAndPasswordTest extends AppTestCase
{
    private const array VEHICLE = ['type' => 'car', 'make' => 'Volkswagen', 'model' => 'Golf', 'fuel_type' => 'petrol'];

    public function testForgedPostIsRejectedWithoutSideEffects(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        $response = $browser->post('/vehicles/new', self::VEHICLE, [], false);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('This form has expired', self::body($response));
        self::assertSame(0, $this->vehicleCount($app));
    }

    public function testTokenFromAnotherSessionIsRejected(): void
    {
        $app = $this->createApp();
        $victim = $this->signedIn($app);

        $attacker = new TestBrowser($app);
        $attacker->get('/login');
        $foreignToken = $attacker->csrfFields();

        $victim->get('/');
        $response = $victim->post('/vehicles/new', self::VEHICLE + $foreignToken, [], false);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->vehicleCount($app));
    }

    public function testTamperedTokenIsRejected(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $token = $browser->csrfFields();
        $token['csrf_value'] = base64_encode(str_repeat('x', 32));

        self::assertSame(400, $browser->post('/vehicles/new', self::VEHICLE + $token, [], false)->getStatusCode());
    }

    public function testValidTokenWorksRepeatedlyAcrossTabs(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $token = $browser->csrfFields();

        // Persistent-token mode: the same token serves several forms/tabs.
        $firstTab = $browser->post('/vehicles/new', self::VEHICLE + $token, [], false);
        $secondTab = $browser->post('/vehicles/new', self::VEHICLE + $token, [], false);
        self::assertSame(303, $firstTab->getStatusCode());
        self::assertSame(303, $secondTab->getStatusCode());
        self::assertSame(2, $this->vehicleCount($app));
    }

    public function testSignInRotatesTheCsrfToken(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $browser = new TestBrowser($app);
        $browser->get('/login');
        $before = $browser->csrfFields();

        $browser->post('/login', ['username' => 'owner', 'password' => self::PASSWORD]);

        self::assertSame(400, $browser->post('/vehicles/new', self::VEHICLE + $before, [], false)->getStatusCode());
    }

    public function testSignInFormItselfIsProtected(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);

        $response = (new TestBrowser($app))->post('/login', ['username' => 'owner', 'password' => self::PASSWORD], [], false);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testOversizedPostIsReportedAsTooLarge(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');

        // PHP drops bodies over post_max_size, CSRF token included.
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/vehicles/new')
            ->withCookieParams(['logbook_session' => (string) $browser->sessionCookie()])
            ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
            ->withHeader('Content-Length', (string) (PHP_INT_MAX >> 1));

        $response = $app->handle($request);

        self::assertSame(413, $response->getStatusCode());
        self::assertStringContainsString('Too large', self::body($response));
    }

    public function testChangePasswordRequiresTheCurrentOne(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->get('/settings');

        $response = $browser->post('/settings/password', [
            'current_password' => 'not it',
            'new_password' => 'a brand new passphrase',
            'new_password_confirm' => 'a brand new passphrase',
        ]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Your current password is not correct.', self::body($response));

        $response = $browser->post('/settings/password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'short',
            'new_password_confirm' => 'short',
        ]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Use between 8 and 1024 characters.', self::body($response));

        $response = $browser->post('/settings/password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'a brand new passphrase',
            'new_password_confirm' => 'a different passphrase',
        ]);
        self::assertStringContainsString('The passwords do not match.', self::body($response));
    }

    public function testChangePasswordSignsOutOtherDevicesOnly(): void
    {
        $app = $this->createApp();
        $phone = $this->signedIn($app);
        $laptop = new TestBrowser($app);
        $laptop->get('/login');
        $laptop->post('/login', ['username' => 'owner', 'password' => self::PASSWORD]);
        self::assertSame(200, $laptop->get('/')->getStatusCode());

        $phone->get('/settings');
        $before = $phone->sessionCookie();
        $response = $phone->post('/settings/password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'a brand new passphrase',
            'new_password_confirm' => 'a brand new passphrase',
        ]);
        self::assertSame('/settings', $response->getHeaderLine('Location'));
        self::assertNotSame($before, $phone->sessionCookie(), 'this session moves to a new id');
        self::assertStringContainsString('Your password was changed.', self::body($phone->follow($response)));

        self::assertSame(303, $laptop->get('/')->getStatusCode(), 'other device signed out');

        $user = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($user);
        self::assertTrue(password_verify('a brand new passphrase', $user->passwordHash));

        $fresh = new TestBrowser($app);
        $fresh->get('/login');
        self::assertSame(422, $fresh->post('/login', ['username' => 'owner', 'password' => self::PASSWORD])->getStatusCode());
        $response = $fresh->post('/login', ['username' => 'owner', 'password' => 'a brand new passphrase']);
        self::assertSame(303, $response->getStatusCode());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function vehicleCount(App $app): int
    {
        $user = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($user);

        return count($this->ownedVehicles($app, $user->id));
    }
}
