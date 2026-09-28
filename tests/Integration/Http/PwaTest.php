<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Feature\Feature;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;

/**
 * The installable app (spec.md §7.15): manifest, service worker and offline
 * page all carry APP_BASE_PATH, so a subpath install works offline too, and
 * the fill-up forms are marked for the offline queue.
 */
final class PwaTest extends AppTestCase
{
    use CostFixtures;

    public function testManifestAtASubpath(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $response = $this->get($app, '/logbook/manifest.webmanifest');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/manifest+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame([], $response->getHeader('Set-Cookie'), 'no session for the browser\'s own fetches');

        /**
         * @var array{
         *     name: string, start_url: string, scope: string, display: string,
         *     icons: list<array{src: string, sizes: string}>, shortcuts: list<array{url: string}>
         * } $manifest
         */
        $manifest = json_decode(self::body($response), true);
        self::assertSame('Logbook', $manifest['name']);
        self::assertSame('/logbook/', $manifest['start_url']);
        self::assertSame('/logbook/', $manifest['scope']);
        self::assertSame('standalone', $manifest['display']);
        self::assertSame(['192x192', '512x512', '512x512'], array_column($manifest['icons'], 'sizes'));
        foreach ($manifest['icons'] as $icon) {
            self::assertMatchesRegularExpression('#^/logbook/assets/images/icon-[a-z0-9-]+\.png\?v=[0-9a-f]+$#', $icon['src']);
            $file = substr(explode('?', $icon['src'])[0], strlen('/logbook/assets/'));
            self::assertFileExists(dirname(__DIR__, 3) . '/public/assets/' . $file);
        }
        self::assertSame('/logbook/fuel/new', $manifest['shortcuts'][0]['url']);

        // Signed-out pages link it too; the proxy may also strip the prefix.
        $html = self::body($this->get($app, '/logbook/diagnostics/deep/link'));
        self::assertStringContainsString('<link rel="manifest" href="/logbook/manifest.webmanifest">', $html);
        self::assertStringContainsString('data-base="/logbook"', $html);
        self::assertSame(200, $this->get($app, '/manifest.webmanifest')->getStatusCode());
    }

    public function testServiceWorkerIsServedFromTheBasePathWithItsSettings(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $response = $this->get($app, '/logbook/sw.js');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/javascript; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame([], $response->getHeader('Set-Cookie'));

        $script = self::body($response);
        self::assertSame(1, preg_match('/^self\.LOGBOOK = (\{.*\});\n/', $script, $m));
        /** @var array{base: string, offline: string, version: string, assets: list<string>} $config */
        $config = json_decode($m[1] ?? '', true);
        self::assertSame('/logbook', $config['base']);
        self::assertSame('/logbook/offline', $config['offline']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+-[0-9a-f]{12}$/', $config['version']);
        self::assertContains(
            true,
            array_map(
                static fn (string $url): bool => str_starts_with($url, '/logbook/assets/css/app.css?v='),
                $config['assets'],
            ),
        );
        foreach ($config['assets'] as $url) {
            self::assertStringStartsWith('/logbook/assets/', $url);
            self::assertStringNotContainsString('THIRD-PARTY', $url);
        }
        self::assertStringContainsString("self.addEventListener('fetch'", $script);
    }

    public function testOfflinePage(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $response = $this->get($app, '/logbook/offline');

        self::assertSame(200, $response->getStatusCode());
        $html = self::body($response);
        self::assertStringContainsString('You’re offline', $html);
        self::assertStringContainsString('href="/logbook/fuel/new"', $html);
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    public function testFillUpFormsAreReadyForOfflineUse(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $this->vehicle($app, 'Volkswagen', 'Polo');

        $form = self::body($browser->get('/vehicles/' . $golf->id . '/fuel/new'));
        self::assertMatchesRegularExpression(
            '#data-offline-queue data-offline-label="Volkswagen Golf" data-zone="Europe/London" data-now-field="filled_at">#',
            $form,
        );
        self::assertStringContainsString('data-offline-outbox hidden', $form);

        // The picker keeps each vehicle's form for offline use.
        $picker = self::body($browser->get('/fuel/new'));
        self::assertSame(2, substr_count($picker, 'data-offline-cache'));

        // A form re-shown with errors keeps what was typed.
        $invalid = self::body($browser->post('/vehicles/' . $golf->id . '/fuel/new', ['filled_at' => '2026-09-01T10:00']));
        self::assertStringContainsString('data-offline-queue', $invalid);
        self::assertStringNotContainsString('data-now-field', $invalid);

        // Without the fuel module there is nothing to queue or install a shortcut for.
        $this->service($app, FeatureToggles::class)->save([Feature::Maintenance]);
        self::assertStringNotContainsString('data-offline-outbox', self::body($browser->get('/garage')));
        $manifest = json_decode(self::body((new TestBrowser($app))->get('/manifest.webmanifest')), true);
        self::assertIsArray($manifest);
        self::assertArrayNotHasKey('shortcuts', $manifest);
    }
}
