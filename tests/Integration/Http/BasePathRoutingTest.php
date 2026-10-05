<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\TestBrowser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The app must work at a subpath behind a reverse proxy, and a hard refresh
 * on a deep link must load the page again (spec.md §11).
 */
final class BasePathRoutingTest extends AppTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function deepLinkRequests(): iterable
    {
        yield 'proxy forwards the prefix' => ['/logbook/diagnostics/deep/link'];
        yield 'proxy strips the prefix' => ['/diagnostics/deep/link'];
    }

    #[DataProvider('deepLinkRequests')]
    public function testDeepLinkLoadsDirectlyAtASubpath(string $path): void
    {
        $response = $this->get($this->createApp(['APP_BASE_PATH' => '/logbook']), $path);
        $html = self::body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Deep link works', $html);
        // Every generated URL carries the prefix.
        self::assertStringContainsString('href="/logbook/"', $html);
        self::assertMatchesRegularExpression('~href="/logbook/assets/css/app\.css\?v=[0-9a-f]+"~', $html);
        self::assertStringNotContainsString('href="/assets/', $html);
        self::assertStringNotContainsString('src="/assets/', $html);
        self::assertStringContainsString('<use href="/logbook/assets/vendor/icons.svg?v=', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rootRequests(): iterable
    {
        yield 'with trailing slash' => ['/logbook/'];
        yield 'without trailing slash' => ['/logbook'];
        yield 'prefix stripped' => ['/'];
    }

    #[DataProvider('rootRequests')]
    public function testHomeAtASubpathRedirectsToPrefixedSetup(string $path): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => 'logbook/']);
        $this->resetDatabase($app);

        $response = $this->get($app, $path);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/logbook/setup', $response->getHeaderLine('Location'));

        $setup = $this->get($app, '/logbook/setup');
        self::assertSame(200, $setup->getStatusCode());
        self::assertStringContainsString('action="/logbook/setup"', self::body($setup));
    }

    public function testSignedInPagesAndSessionCookieAtASubpath(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $browser = new TestBrowser($app);

        // Deep link while signed out: sign in, then land back on it.
        $redirect = $browser->get('/logbook/vehicles/new');
        self::assertSame('/logbook/login?next=%2Flogbook%2Fvehicles%2Fnew', $redirect->getHeaderLine('Location'));

        $browser->get('/logbook/login');
        $response = $browser->post('/logbook/login', [
            'username' => 'owner',
            'password' => self::PASSWORD,
            'next' => '/logbook/vehicles/new',
        ]);
        self::assertSame('/logbook/vehicles/new', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Path=/logbook;', $response->getHeaderLine('Set-Cookie'));

        // Hard refresh of the deep link, prefix stripped by the proxy.
        $page = $browser->get('/vehicles/new');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('action="/logbook/vehicles/new"', self::body($page));
        self::assertStringContainsString('href="/logbook/garage"', self::body($page));
    }

    public function testPhase333PagesLinkWithThePrefix(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $browser = new TestBrowser($app);
        $browser->get('/logbook/login');
        $browser->post('/logbook/login', ['username' => 'owner', 'password' => self::PASSWORD]);
        $browser->get('/vehicles/new');
        $created = $browser->post('/vehicles/new', [
            'type' => 'car',
            'make' => 'Toyota',
            'model' => 'Yaris',
            'fuel_type' => 'petrol',
            'currency' => '',
        ]);
        self::assertSame(303, $created->getStatusCode(), self::body($created));
        self::assertSame(1, preg_match('#^/logbook/vehicles/(\d+)#', $created->getHeaderLine('Location'), $m));
        $id = $m[1] ?? '';

        // The Finance tab (hard refresh, prefix stripped) and its tab strip.
        $finance = $browser->get('/vehicles/' . $id . '/finance');
        self::assertSame(200, $finance->getStatusCode());
        $html = self::body($finance);
        self::assertStringContainsString('href="/logbook/vehicles/' . $id . '/finance"', $html);
        self::assertStringContainsString('href="/logbook/vehicles/' . $id . '/finance/new"', $html);
        self::assertStringContainsString('href="/logbook/vehicles/' . $id . '/incidents"', $html);

        // The claims history's script goes through the asset helper.
        $history = self::body($browser->get('/incidents/history'));
        self::assertStringContainsString('src="/logbook/assets/js/claims-history.js', $history);

        // The dashboard (Insights and Your vehicles) keeps the prefix.
        $dashboard = self::body($browser->get('/'));
        self::assertStringContainsString('href="/logbook/vehicles/' . $id . '"', $dashboard);
        self::assertStringNotContainsString('href="/vehicles/', $dashboard);
    }

    public function testHealthAtASubpath(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);

        self::assertSame(200, $this->get($app, '/logbook/health')->getStatusCode());
        self::assertSame(200, $this->get($app, '/health')->getStatusCode());
    }

    public function testUnknownRouteAtASubpathIsA404WithPrefixedLinks(): void
    {
        $response = $this->get($this->createApp(['APP_BASE_PATH' => '/logbook']), '/logbook/no/such/page');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('href="/logbook/"', self::body($response));
    }

    public function testDeepLinkAtTheRoot(): void
    {
        $response = $this->get($this->createApp(), '/diagnostics/deep/link');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('href="/"', self::body($response));
    }
}
