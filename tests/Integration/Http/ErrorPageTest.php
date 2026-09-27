<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Support\Http\HtmlErrorRenderer;
use Logbook\Tests\Support\AppTestCase;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ErrorPageTest extends AppTestCase
{
    public function testNotFoundIsAFriendlyTranslatedPage(): void
    {
        $response = $this->get($this->createApp(), '/definitely/not/here');
        $html = self::body($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Page not found', $html);
        self::assertStringContainsString('Error 404', $html);
        self::assertStringNotContainsString('error.404', $html);
    }

    public function testWrongMethodIs405(): void
    {
        $app = $this->createApp();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('POST', '/health'));

        self::assertSame(405, $response->getStatusCode());
        self::assertStringContainsString('GET', $response->getHeaderLine('Allow'));
    }

    public function testProductionHidesExceptionDetails(): void
    {
        $renderer = $this->service($this->createApp(), HtmlErrorRenderer::class);

        $html = $renderer(new RuntimeException('secret internals at /srv/app'), false);

        self::assertStringContainsString('Something went wrong', $html);
        self::assertStringNotContainsString('secret internals', $html);
    }

    public function testDebugDetailsAreHtmlEscaped(): void
    {
        $renderer = $this->service($this->createApp(), HtmlErrorRenderer::class);

        $html = $renderer(new RuntimeException('<script>alert("xss")</script>'), true);

        self::assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
    }

    public function testJsonClientsGetJsonErrors(): void
    {
        $response = $this->get($this->createApp(), '/nope', ['Accept' => 'application/json']);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    }
}
