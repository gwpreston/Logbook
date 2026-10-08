<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * The API behind a subpath, switched off, and called from a browser
 * dashboard (spec.md §7.20, §9).
 */
final class ApiConfigTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    private const string ORIGIN = 'https://dash.example:8123';

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testItWorksUnderTheBasePathWithOrWithoutTheProxyStrippingIt(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook', 'APP_URL' => 'https://cars.example/logbook']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '10000', '40', '60');
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10500', '40', '60');
        $token = $this->apiKey($app, $owner);

        $api = $this->api($app, $token, '/logbook/api/v1');
        $page = ApiClient::json($api->get('/vehicles/' . $golf->id . '/fuel?limit=1'));
        self::assertCount(1, $page->doc('items'));
        self::assertIsString($page->get('next'));
        self::assertStringStartsWith('https://cars.example/logbook/api/v1/vehicles/' . $golf->id . '/fuel?', $page->get('next'));
        self::assertSame(
            'https://cars.example/logbook/api/v1',
            ApiClient::json($api->get('/openapi.json'))->get('servers', 0, 'url'),
        );

        $stripped = $this->api($app, $token, '/api/v1');
        self::assertSame(200, $stripped->get('/vehicles')->getStatusCode(), 'a proxy that strips the prefix');
        $missing = $api->get('/nowhere');
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('application/problem+json', $missing->getHeaderLine('Content-Type'));
    }

    public function testSwitchedOffEveryApiPathIsNotFound(): void
    {
        $app = $this->createApp(['API_ENABLED' => 'false']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $api = $this->api($app, $this->apiKey($app, $owner));

        foreach (['/me', '/vehicles', '/openapi.json'] as $path) {
            $response = $api->get($path);
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertSame('not_found', ApiClient::json($response)->get('code'));
        }
        self::assertSame(404, $api->post('/vehicles/1/fuel', ['odometer' => '1'])->getStatusCode());
        self::assertSame(200, $this->get($app, '/health')->getStatusCode(), 'the rest of the app is unaffected');
    }

    public function testAnUnknownMethodIsAProblemToo(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $api = $this->api($app, $this->apiKey($app, $this->createOwner($app)));

        $response = $api->send('DELETE', '/vehicles');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('method_not_allowed', ApiClient::json($response)->get('code'));
        self::assertStringContainsString('GET', $response->getHeaderLine('Allow'));
    }

    public function testCorsIsOffUnlessAnOriginIsListed(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $api = $this->api($app, $this->apiKey($app, $this->createOwner($app)));

        $response = $api->get('/me', ['Origin' => self::ORIGIN]);
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));

        $preflight = $api->send('OPTIONS', '/me', null, ['Origin' => self::ORIGIN, 'Access-Control-Request-Method' => 'GET']);
        self::assertSame(403, $preflight->getStatusCode());
        self::assertSame('cors_not_allowed', ApiClient::json($preflight)->get('code'));
    }

    public function testAListedOriginGetsCorsHeadersAndItsPreflight(): void
    {
        $app = $this->createApp(['API_CORS_ORIGINS' => 'https://other.example, ' . self::ORIGIN . '/']);
        $this->resetDatabase($app);
        $api = $this->api($app, $this->apiKey($app, $this->createOwner($app)));

        $preflight = $api->send('OPTIONS', '/vehicles/1/fuel', null, [
            'Origin' => self::ORIGIN,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type',
        ]);
        self::assertSame(204, $preflight->getStatusCode());
        self::assertSame(self::ORIGIN, $preflight->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('POST', $preflight->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Authorization', $preflight->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertFalse($preflight->hasHeader('Access-Control-Allow-Credentials'));

        $response = $api->get('/me', ['Origin' => self::ORIGIN]);
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
        // Single-entry reads carry an ETag (Phase 39.1) that a page's script may read.
        self::assertSame('ETag', $response->getHeaderLine('Access-Control-Expose-Headers'));
        $denied = $api->withToken(null)->get('/me', ['Origin' => self::ORIGIN]);
        self::assertSame(401, $denied->getStatusCode());
        self::assertSame(
            self::ORIGIN,
            $denied->getHeaderLine('Access-Control-Allow-Origin'),
            'errors too, so the page can read them',
        );

        $stranger = $api->send('OPTIONS', '/me', null, ['Origin' => 'https://evil.example']);
        self::assertSame(403, $stranger->getStatusCode());
        self::assertFalse($api->get('/me', ['Origin' => 'https://evil.example'])->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse(
            $this->get($app, '/health', ['Origin' => self::ORIGIN])->hasHeader('Access-Control-Allow-Origin'),
            'only the API',
        );
    }
}
