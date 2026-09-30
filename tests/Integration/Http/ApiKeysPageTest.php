<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Api\ApiScope;
use Logbook\Repository\ApiKeyRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Api\ApiKeyService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Security\PasswordHasher;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;

/**
 * Settings → API keys (spec.md §7.20): create a key and see its token once,
 * never again and never in the session; list keys; revoke one after a
 * confirmation, with immediate effect.
 */
final class ApiKeysPageTest extends AppTestCase
{
    use ApiFixtures;

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testCreatingAKeyShowsItsTokenOnceAndItWorks(): void
    {
        $app = $this->createApp(['APP_URL' => 'http://localhost:8080']);
        $browser = $this->signedIn($app);

        $settings = self::body($browser->get('/settings'));
        self::assertStringContainsString('href="/settings/api-keys"', $settings);
        $page = $browser->get('/settings/api-keys');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('No keys yet.', self::body($page));
        self::assertStringContainsString('http://localhost:8080/api/v1', self::body($page));

        $created = $browser->post('/settings/api-keys', ['name' => 'Home Assistant', 'scope' => 'read']);
        self::assertSame(200, $created->getStatusCode());
        self::assertSame('no-store', $created->getHeaderLine('Cache-Control'));
        $html = self::body($created);
        self::assertSame(1, preg_match('/value="(lbk_[A-Za-z0-9_-]{43})"/', $html, $m), 'the token is shown');
        $token = $m[1] ?? '';
        self::assertStringContainsString('Copy the key now: it will not be shown again.', $html);
        self::assertStringContainsString('data-copy="#f-api-token"', $html);

        $sessions = implode("\n", array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $this->connection($app)->fetchFirstColumn('SELECT data FROM sessions'),
        ));
        self::assertStringNotContainsString($token, $sessions, 'never through the session');
        self::assertSame(200, $this->api($app, $token)->get('/me')->getStatusCode());
        self::assertSame(
            403,
            $this->api($app, $token)->post('/vehicles/1/odometer', ['odometer' => '1'])->getStatusCode(),
            'read only',
        );

        $again = self::body($browser->get('/settings/api-keys'));
        self::assertStringNotContainsString($token, $again, 'shown once');
        self::assertStringContainsString('Home Assistant', $again);
        self::assertStringContainsString('Read only', $again);
        self::assertStringContainsString('Last used', $again);
    }

    public function testANameAndAScopeAreRequired(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->get('/settings/api-keys');

        $response = $browser->post('/settings/api-keys', ['name' => '  ', 'scope' => 'admin']);

        self::assertSame(422, $response->getStatusCode());
        $html = self::body($response);
        self::assertStringContainsString('This field is required.', $html);
        self::assertStringContainsString('Choose one of the options.', $html);
        self::assertDoesNotMatchRegularExpression('/lbk_[A-Za-z0-9_-]{43}/', $html);
        self::assertSame([], $this->service($app, ApiKeyRepository::class)->listAll());
        $tooLong = $browser->post('/settings/api-keys', ['name' => str_repeat('n', 101), 'scope' => 'read']);
        self::assertSame(422, $tooLong->getStatusCode());
    }

    public function testCreatingNeedsTheCsrfToken(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        $response = $browser->post('/settings/api-keys', ['name' => 'Sneaky', 'scope' => 'read_write'], [], false);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->service($app, ApiKeyRepository::class)->listAll());
    }

    public function testRevokingAfterConfirmationStopsTheKeyAtOnce(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);
        $created = $this->service($app, ApiKeyService::class)->create($owner, 'Node-RED', ApiScope::ReadWrite);
        $url = '/settings/api-keys/' . $created->key->id . '/revoke';

        $list = self::body($browser->get('/settings/api-keys'));
        self::assertStringContainsString('href="' . $url . '"', $list);
        self::assertStringContainsString('Never used', $list);
        $confirm = $browser->get($url);
        self::assertSame(200, $confirm->getStatusCode());
        self::assertStringContainsString('Revoke “Node-RED”?', html_entity_decode(self::body($confirm)));

        $done = $browser->post($url);
        self::assertSame(303, $done->getStatusCode());
        $after = html_entity_decode(self::body($browser->follow($done)));
        self::assertStringContainsString('The key “Node-RED” was revoked.', $after);
        self::assertStringContainsString('Revoked', $after);
        self::assertStringNotContainsString('href="' . $url . '"', $after);
        self::assertSame(401, $this->api($app, $created->token)->get('/me')->getStatusCode());
        self::assertSame(404, $browser->get($url)->getStatusCode(), 'revoked for good');
    }

    public function testSomeoneElsesKeyIsNotFound(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $other = $this->service($app, UserRepository::class)->insert(
            'other',
            $this->service($app, PasswordHasher::class)->hash(self::PASSWORD),
            'Sam Other',
            DisplayPreferences::defaults('en_GB', 'Europe/London', 'GBP'),
            new \DateTimeImmutable('2026-09-01T00:00:00Z'),
        );
        $theirs = $this->service($app, ApiKeyService::class)->create($other, 'Theirs', ApiScope::Read);

        self::assertStringNotContainsString('Theirs', self::body($browser->get('/settings/api-keys')));
        self::assertSame(404, $browser->get('/settings/api-keys/' . $theirs->key->id . '/revoke')->getStatusCode());
        self::assertSame(404, $browser->post('/settings/api-keys/' . $theirs->key->id . '/revoke')->getStatusCode());
        self::assertSame(200, $this->api($app, $theirs->token)->get('/me')->getStatusCode(), 'untouched');
    }

    public function testThePageSaysWhenTheApiIsSwitchedOff(): void
    {
        $app = $this->createApp(['API_ENABLED' => 'false']);
        $browser = $this->signedIn($app);

        $html = self::body($browser->get('/settings/api-keys'));

        self::assertStringContainsString('The API is switched off on this install', $html);
        self::assertStringNotContainsString('OpenAPI description', $html);
    }
}
