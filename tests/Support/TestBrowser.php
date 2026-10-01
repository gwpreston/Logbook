<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Middleware\SessionMiddleware;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Drives the app like a browser: keeps cookies between requests and fills in
 * the CSRF fields from the last page it saw, so tests exercise the real
 * session and CSRF middleware.
 */
final class TestBrowser
{
    /** @var array<string, string> */
    private array $cookies = [];
    private string $lastHtml = '';

    private string $remoteAddress = '192.0.2.10';
    /** @var array<string, string> */
    private array $defaultHeaders = [];

    /**
     * @param App<ContainerInterface> $app
     */
    public function __construct(private readonly App $app)
    {
    }

    /**
     * Connect from this address from now on (REMOTE_ADDR), as a proxy would.
     */
    public function from(string $address): self
    {
        $this->remoteAddress = $address;

        return $this;
    }

    /**
     * Send these headers with every request from now on (an empty value
     * stops sending one), as a sign-in proxy adds its header.
     *
     * @param array<string, string> $headers
     */
    public function sending(array $headers): self
    {
        foreach ($headers as $name => $value) {
            if ($value === '') {
                unset($this->defaultHeaders[$name]);
            } else {
                $this->defaultHeaders[$name] = $value;
            }
        }

        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $path, [], [], $headers);
    }

    /**
     * POST a form, adding the session's CSRF token unless $withCsrf is false
     * (fetching a page first if none has been seen yet).
     *
     * @param array<string, string|list<string>> $fields (a list for multi-value fields such as checkboxes[])
     * @param array<string, UploadedFileInterface|list<UploadedFileInterface>> $files (a list for attachments[])
     * @param array<string, string> $headers e.g. the X-Requested-With a script sends
     */
    public function post(
        string $path,
        array $fields = [],
        array $files = [],
        bool $withCsrf = true,
        array $headers = [],
    ): ResponseInterface {
        if ($withCsrf) {
            $fields += $this->csrfFields();
        }

        return $this->request('POST', $path, $fields, $files, $headers);
    }

    /**
     * Follow a redirect response with a GET.
     */
    public function follow(ResponseInterface $response): ResponseInterface
    {
        Assert::assertContains($response->getStatusCode(), [301, 302, 303], 'expected a redirect');

        return $this->get($response->getHeaderLine('Location'));
    }

    public function sessionCookie(): ?string
    {
        return $this->cookies[SessionMiddleware::COOKIE] ?? null;
    }

    public function setCookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    public function forgetCookies(): void
    {
        $this->cookies = [];
    }

    /**
     * @return array<string, string> csrf_name / csrf_value from the last page
     */
    public function csrfFields(): array
    {
        foreach (['/settings', '/login'] as $page) {
            if ($this->pageHasCsrfToken()) {
                break;
            }
            $this->get($page);
        }

        if (
            preg_match('/name="csrf_name" value="([^"]+)"/', $this->lastHtml, $name) !== 1
            || preg_match('/name="csrf_value" value="([^"]+)"/', $this->lastHtml, $value) !== 1
        ) {
            Assert::fail('no CSRF token on the page');
        }

        return ['csrf_name' => html_entity_decode($name[1]), 'csrf_value' => html_entity_decode($value[1])];
    }

    private function pageHasCsrfToken(): bool
    {
        return str_contains($this->lastHtml, 'name="csrf_name"');
    }

    /**
     * @param array<string, string|list<string>> $fields (a list for multi-value fields such as checkboxes[])
     * @param array<string, UploadedFileInterface|list<UploadedFileInterface>> $files (a list for attachments[])
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array $fields, array $files, array $headers = []): ResponseInterface
    {
        // As Apache 2.4 hands them to PHP: every header also an HTTP_* variable,
        // except names with an underscore, which it drops there (and only there).
        $server = ['REMOTE_ADDR' => $this->remoteAddress];
        foreach ($headers + $this->defaultHeaders as $name => $value) {
            if (!str_contains($name, '_')) {
                $server['HTTP_' . strtoupper(strtr($name, '-', '_'))] = $value;
            }
        }
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, $server)
            ->withCookieParams($this->cookies);
        foreach ($headers + $this->defaultHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($method === 'POST') {
            $request = $request
                ->withHeader('Content-Type', $files === [] ? 'application/x-www-form-urlencoded' : 'multipart/form-data')
                ->withParsedBody($fields)
                ->withUploadedFiles($files);
        }

        $response = $this->app->handle($request);
        $this->storeCookies($response);

        $body = (string) $response->getBody();
        if (str_starts_with($response->getHeaderLine('Content-Type'), 'text/html') && $body !== '') {
            $this->lastHtml = $body;
        }

        return $response;
    }

    private function storeCookies(ResponseInterface $response): void
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            [$pair] = explode(';', $header, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($name === SessionMiddleware::COOKIE && ($this->cookies[$name] ?? null) !== $value) {
                // A new session id means a new CSRF token: forget the old page.
                $this->lastHtml = '';
            }
            if ($value === '' || preg_match('/Max-Age=0\b/', $header) === 1) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
    }
}
