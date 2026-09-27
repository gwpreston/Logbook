<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Tests\Support\AppTestCase;
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
    public function testHomeAtASubpath(string $path): void
    {
        $response = $this->get($this->createApp(['APP_BASE_PATH' => 'logbook/']), $path);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('href="/logbook/health"', self::body($response));
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
