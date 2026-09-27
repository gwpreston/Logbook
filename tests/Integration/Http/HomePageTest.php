<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Tests\Support\AppTestCase;

final class HomePageTest extends AppTestCase
{
    public function testRendersTheTranslatedLandingPage(): void
    {
        $response = $this->get($this->createApp(), '/');
        $html = self::body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('en', $response->getHeaderLine('Content-Language'));
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('Welcome to Logbook', $html);
        // ICU plural from the catalogue, with count = 0.
        self::assertStringContainsString('No vehicles yet', $html);
        self::assertStringNotContainsString('home.title', $html, 'translation keys must not leak into output');
    }

    public function testLinksAssetsAndVendoredLibraries(): void
    {
        $html = self::body($this->get($this->createApp(), '/'));

        self::assertMatchesRegularExpression('~href="/assets/css/app\.css\?v=[0-9a-f]+"~', $html);
        self::assertStringContainsString('/assets/vendor/alpine.min.js', $html);
        self::assertStringContainsString('/assets/vendor/chart.umd.min.js', $html);
        self::assertStringContainsString('/assets/vendor/sortable.min.js', $html);
    }

    public function testRendersTheAppShell(): void
    {
        $html = self::body($this->get($this->createApp(), '/'));

        $v = '\?v=[0-9a-f]+';

        // Brand lock-up with a translated accessible name.
        self::assertMatchesRegularExpression("~<img class=\"brand__mark\" src=\"/assets/images/logbook-mark\\.png{$v}\"~", $html);
        self::assertStringContainsString('<span class="visually-hidden">Logbook</span>', $html);
        // Sidebar and bottom tab bar both mark the current page.
        self::assertSame(2, substr_count($html, 'aria-current="page"'));
        self::assertStringContainsString('Dashboard', $html);
        // Icons come from the self-hosted sprite; no third-party font/CDN requests.
        self::assertMatchesRegularExpression("~<use href=\"/assets/vendor/icons\\.svg{$v}#space_dashboard\">~", $html);
        self::assertStringNotContainsString('fonts.googleapis.com', $html);
        // Theme script runs before first paint; the toggle stays hidden without JS.
        self::assertMatchesRegularExpression("~<script src=\"/assets/js/theme\\.js{$v}\"></script>~", $html);
        self::assertStringContainsString('data-theme-toggle hidden', $html);
        self::assertStringContainsString('Dark mode', $html);
    }

    public function testUnsupportedBrowserLanguageFallsBackToEnglish(): void
    {
        $response = $this->get($this->createApp(), '/', ['Accept-Language' => 'xx-YY, zz;q=0.5']);

        self::assertSame('en', $response->getHeaderLine('Content-Language'));
        self::assertStringContainsString('Welcome to Logbook', self::body($response));
    }

    public function testHeadRequestIsServed(): void
    {
        $app = $this->createApp();
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('HEAD', '/');

        self::assertSame(200, $app->handle($request)->getStatusCode());
    }
}
