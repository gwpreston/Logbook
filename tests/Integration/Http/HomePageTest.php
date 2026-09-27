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
