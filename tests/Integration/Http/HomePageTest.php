<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Tests\Support\AppTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HomePageTest extends AppTestCase
{
    public function testRendersTheSignedInDashboard(): void
    {
        $response = $this->signedIn($this->createApp())->get('/');
        $html = self::body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        // The owner's locale (en_GB) applies: English catalogue, British formats.
        self::assertSame('en-GB', $response->getHeaderLine('Content-Language'));
        self::assertStringContainsString('<html lang="en-GB"', $html);
        self::assertStringContainsString('Hello, Pat Owner', $html);
        // ICU plural from the catalogue, with count = 0.
        self::assertStringContainsString('No vehicles yet', $html);
        self::assertStringNotContainsString('home.greeting', $html, 'translation keys must not leak into output');
        // Personal pages are never cached by shared caches.
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testLinksAssetsAndVendoredLibraries(): void
    {
        $html = self::body($this->signedIn($this->createApp())->get('/'));

        self::assertMatchesRegularExpression('~href="/assets/css/app\.css\?v=[0-9a-f]+"~', $html);
        self::assertStringContainsString('/assets/vendor/alpine.min.js', $html);
        self::assertStringContainsString('/assets/vendor/chart.umd.min.js', $html);
        self::assertStringContainsString('/assets/vendor/sortable.min.js', $html);
    }

    public function testRendersTheAppShell(): void
    {
        $html = self::body($this->signedIn($this->createApp())->get('/'));

        $v = '\?v=[0-9a-f]+';

        // Brand lock-up with a translated accessible name.
        self::assertMatchesRegularExpression("~<img class=\"brand__mark\" src=\"/assets/images/logbook-mark\\.png{$v}\"~", $html);
        self::assertStringContainsString('<span class="visually-hidden">Logbook</span>', $html);
        // Sidebar and bottom tab bar both mark the current page.
        self::assertSame(2, substr_count($html, 'aria-current="page"'));
        self::assertStringContainsString('Dashboard', $html);
        self::assertStringContainsString('href="/garage"', $html);
        self::assertStringContainsString('href="/settings"', $html);
        // Icons come from the self-hosted sprite; no third-party font/CDN requests.
        self::assertMatchesRegularExpression("~<use href=\"/assets/vendor/icons\\.svg{$v}#space_dashboard\">~", $html);
        self::assertStringNotContainsString('fonts.googleapis.com', $html);
        // Theme script runs before first paint; the toggle stays hidden without JS
        // and, signed in, saves the preference through a CSRF-protected form.
        self::assertMatchesRegularExpression("~<script src=\"/assets/js/theme\\.js{$v}\"></script>~", $html);
        self::assertStringContainsString('data-theme-toggle hidden', $html);
        self::assertStringContainsString('data-theme-pref="system"', $html);
        self::assertStringContainsString('action="/settings/theme"', $html);
        // Sign-out is a POST form.
        self::assertMatchesRegularExpression(
            '~<form method="post" action="/logout">\s*<input type="hidden" name="csrf_name"~',
            $html,
        );
    }

    public function testSignedOutVisitorsSeeTheSignInPageInTheirLanguageOrEnglish(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);

        $redirect = $this->get($app, '/', ['Accept-Language' => 'xx-YY, zz;q=0.5']);
        self::assertSame(303, $redirect->getStatusCode());
        self::assertSame('/login?next=%2F', $redirect->getHeaderLine('Location'));

        $response = $this->get($app, '/login', ['Accept-Language' => 'xx-YY, zz;q=0.5']);
        self::assertSame('en', $response->getHeaderLine('Content-Language'));
        self::assertStringContainsString('Sign in', self::body($response));
        // The signed-out shell has no navigation.
        self::assertStringNotContainsString('class="bottom-nav"', self::body($response));

        $british = $this->get($app, '/login', ['Accept-Language' => 'en-GB,en;q=0.8']);
        self::assertSame('en-GB', $british->getHeaderLine('Content-Language'));
    }

    public function testHeadRequestIsServed(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);

        $request = (new ServerRequestFactory())->createServerRequest('HEAD', '/login');

        self::assertSame(200, $app->handle($request)->getStatusCode());
    }
}
