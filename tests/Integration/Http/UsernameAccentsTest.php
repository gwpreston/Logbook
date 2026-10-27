<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Repository\UserRepository;
use Logbook\Service\User\UserAdmin;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\HeaderSignIn;
use Logbook\Tests\Support\TestBrowser;

/**
 * `josé` and `jose` are different usernames on every engine (spec.md §6
 * *User*, §7.9; #452, K44). MySQL and MariaDB used to match them.
 */
final class UsernameAccentsTest extends AppTestCase
{
    use HeaderSignIn;

    protected function tearDown(): void
    {
        $this->removeThrottleDirs();
        parent::tearDown();
    }

    public function testAnUnaccentedNameCanBeAddedBesideAnAccentedOne(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app, 'josé');
        $admin = $this->service($app, UserAdmin::class);

        self::assertTrue($admin->isUsernameFree('jose'), 'not taken by josé');
        self::assertFalse($admin->isUsernameFree('josé'));

        $other = $admin->addUser($owner, 'jose', 'Jose Plain', 'jose@example.test', false, $owner->preferences);
        $users = $this->service($app, UserRepository::class);
        self::assertSame($other->id, $users->findByUsername('jose')?->id);
        self::assertSame($owner->id, $users->findByUsername('josé')?->id);

        $admin->invite($owner, 'renée', 'Renee Accent', false);
        self::assertFalse($admin->isUsernameFree('renée'), 'reserved by the invite');
        self::assertTrue($admin->isUsernameFree('renee'), 'the plain spelling is another name');
    }

    public function testSigningInAsTheUnaccentedNameDoesNotSignInTheAccentedUser(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app, 'josé');

        $browser = new TestBrowser($app);
        $browser->get('/login');
        $refused = $browser->post('/login', ['username' => 'jose', 'password' => self::PASSWORD]);
        self::assertNotSame(303, $refused->getStatusCode(), 'jose is not josé');
        self::assertSame(303, $browser->get('/')->getStatusCode(), 'still signed out');

        $browser->post('/login', ['username' => 'josé', 'password' => self::PASSWORD]);
        self::assertSame(200, $browser->get('/')->getStatusCode(), 'josé signs in as josé');
    }

    public function testAProxyUsernameWithoutTheAccentDoesNotLinkTheAccentedUser(): void
    {
        [$app] = $this->proxyApp();
        $this->createOwner($app, 'josé');

        $plain = $this->viaProxy($app, ['Remote-User' => 'jose']);
        self::assertStringStartsWith('/login', $plain->get('/')->getHeaderLine('Location'), 'jose is not josé');

        $exact = $this->viaProxy($app, ['Remote-User' => 'josé']);
        self::assertSame(303, $exact->get('/')->getStatusCode(), 'josé is signed in, then the page again');
        self::assertSame(200, $exact->get('/')->getStatusCode());
    }
}
