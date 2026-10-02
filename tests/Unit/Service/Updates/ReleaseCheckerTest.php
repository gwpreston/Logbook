<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Updates;

use DateTimeImmutable;
use Logbook\Kernel;
use Logbook\Service\Updates\ReleaseChecker;
use Logbook\Service\Updates\UpdateErrorCode;
use Logbook\Service\Updates\UpdateStatus;
use Logbook\Support\Version\InstalledVersion;
use Logbook\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The update check's request and what it makes of GitHub's answers
 * (spec.md §7.31), with recorded responses: CI needs no network.
 */
final class ReleaseCheckerTest extends TestCase
{
    private const string NOW = '2026-10-02T09:00:00Z';
    private const string LATEST = 'https://api.github.com/repos/gwpreston16/Logbook/releases/latest';

    /** @var list<MockResponse> */
    private array $queue = [];
    /** @var list<array{url: string, headers: array<string, list<string>>, timeout: mixed, max_duration: mixed, max_redirects: mixed}> */
    private array $requests = [];
    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MutableClock(new DateTimeImmutable(self::NOW));
    }

    public function testANewerReleaseIsReadWithTheHeadersGitHubAsksFor(): void
    {
        $this->queue[] = self::release('v2.12.0', etag: '"abc"');

        $status = $this->checker()->check(new UpdateStatus());

        self::assertNull($status->error);
        self::assertSame('2.12.0', $status->latest);
        self::assertSame('https://github.com/gwpreston16/Logbook/releases/tag/v2.12.0', $status->releaseUrl);
        self::assertSame('Logbook 2.12.0', $status->releaseName);
        self::assertEquals(new DateTimeImmutable('2026-10-01T08:00:00Z'), $status->publishedAt);
        self::assertSame('"abc"', $status->etag);
        self::assertEquals(new DateTimeImmutable(self::NOW), $status->checkedAt);

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame(self::LATEST, $request['url']);
        self::assertSame(['application/vnd.github+json'], $request['headers']['accept']);
        self::assertSame(['2022-11-28'], $request['headers']['x-github-api-version']);
        self::assertSame(['Logbook/2.11.0 (+https://github.com/gwpreston16/Logbook)'], $request['headers']['user-agent']);
        self::assertArrayNotHasKey('if-none-match', $request['headers'], 'no ETag yet');
        self::assertEquals(10, $request['timeout']);
        self::assertEquals(10, $request['max_duration']);
        self::assertSame(0, $request['max_redirects']);
    }

    public function testTheSameAndAnOlderReleaseAreStoredAsTheyAre(): void
    {
        $this->queue[] = self::release('2.11.0');
        $this->queue[] = self::release('2.10.0');

        self::assertSame('2.11.0', $this->checker()->check(new UpdateStatus())->latest);
        self::assertSame('2.10.0', $this->checker()->check(new UpdateStatus())->latest);
    }

    public function testNotModifiedKeepsTheReleaseAndUpdatesLastChecked(): void
    {
        $this->queue[] = self::release('2.12.0', etag: 'W/"abc"');
        $this->queue[] = new MockResponse('', ['http_code' => 304]);

        $first = $this->checker()->check(new UpdateStatus());
        $this->clock->set(new DateTimeImmutable('2026-10-03T09:00:00Z'));
        $second = $this->checker()->check($first);

        self::assertSame(['W/"abc"'], $this->requests[1]['headers']['if-none-match']);
        self::assertSame('2.12.0', $second->latest);
        self::assertNull($second->error);
        self::assertEquals(new DateTimeImmutable('2026-10-03T09:00:00Z'), $second->checkedAt);
    }

    public function testNoReleasesYet(): void
    {
        $this->queue[] = new MockResponse('{"message":"Not Found"}', ['http_code' => 404]);

        $status = $this->checker()->check(new UpdateStatus());

        self::assertSame(UpdateErrorCode::NoReleases, $status->error?->code);
        self::assertNull($status->latest);
    }

    public function testRetryAfterIsWaitedOutAndCheckNowSendsNothingMeanwhile(): void
    {
        $this->queue[] = new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 120']]);

        $limited = $this->checker()->check(new UpdateStatus());

        self::assertSame(UpdateErrorCode::RateLimited, $limited->error?->code);
        self::assertEquals(new DateTimeImmutable('2026-10-02T09:02:00Z'), $limited->retryAt);

        // A second check (a pass or Check now) before then sends nothing (#117).
        $this->clock->set(new DateTimeImmutable('2026-10-02T09:01:00Z'));
        $again = $this->checker()->check($limited);
        self::assertCount(1, $this->requests);
        self::assertSame(UpdateErrorCode::RateLimited, $again->error?->code);
        self::assertSame('2026-10-02T09:02:00+00:00', $again->error->params['until']);

        $this->clock->set(new DateTimeImmutable('2026-10-02T09:02:00Z'));
        $this->queue[] = self::release('2.12.0');
        $after = $this->checker()->check($again);
        self::assertCount(2, $this->requests);
        self::assertNull($after->error);
        self::assertNull($after->retryAt);
    }

    public function testTheRateLimitResetIsUsedWithoutRetryAfter(): void
    {
        $reset = (new DateTimeImmutable('2026-10-02T09:30:00Z'))->getTimestamp();
        $this->queue[] = new MockResponse('', [
            'http_code' => 403,
            'response_headers' => ['X-RateLimit-Remaining: 0', 'X-RateLimit-Reset: ' . $reset],
        ]);

        $status = $this->checker()->check(new UpdateStatus());

        self::assertSame(UpdateErrorCode::RateLimited, $status->error?->code);
        self::assertEquals(new DateTimeImmutable('2026-10-02T09:30:00Z'), $status->retryAt);
    }

    public function testATimeout(): void
    {
        $this->queue[] = new MockResponse(['', '{}'], ['http_code' => 200]);

        $status = $this->checker()->check(new UpdateStatus());

        self::assertSame(UpdateErrorCode::Timeout, $status->error?->code);
    }

    public function testANetworkError(): void
    {
        $this->queue[] = new MockResponse('', ['error' => 'Could not resolve host: api.github.com']);

        $status = $this->checker()->check(new UpdateStatus());

        self::assertSame(UpdateErrorCode::Network, $status->error?->code);
        self::assertStringContainsString('Could not resolve host', $status->error->params['reason']);
    }

    public function testABodyOverOneMegabyteIsRefusedWhileReading(): void
    {
        $chunks = (static function (): iterable {
            for ($i = 0; $i < 20; $i++) {
                yield str_repeat('x', 65536);
            }
        })();
        $this->queue[] = new MockResponse($chunks, ['http_code' => 200]);

        $status = $this->checker()->check(new UpdateStatus());

        self::assertSame(UpdateErrorCode::TooLarge, $status->error?->code);
    }

    public function testADeclaredLengthOverOneMegabyteIsRefusedBeforeReading(): void
    {
        $this->queue[] = new MockResponse(str_repeat(' ', 2000000), [
            'http_code' => 200,
            'response_headers' => ['Content-Length: 2000000'],
        ]);

        self::assertSame(UpdateErrorCode::TooLarge, $this->checker()->check(new UpdateStatus())->error?->code);
    }

    public function testARedirectWithinApiGithubComIsFollowed(): void
    {
        $this->queue[] = self::redirect('https://api.github.com/repositories/1390111894/releases/latest');
        $this->queue[] = self::release('2.12.0');

        $status = $this->checker()->check(new UpdateStatus());

        self::assertNull($status->error);
        self::assertSame('2.12.0', $status->latest);
        self::assertSame('https://api.github.com/repositories/1390111894/releases/latest', $this->requests[1]['url']);
    }

    public function testARedirectToAnotherHostIsRefused(): void
    {
        $this->queue[] = self::redirect('https://example.com/releases/latest');

        $status = $this->checker()->check(new UpdateStatus());

        self::assertSame(UpdateErrorCode::Redirect, $status->error?->code);
        self::assertCount(1, $this->requests, 'never followed');
    }

    public function testAtMostTwoRedirects(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->queue[] = self::redirect('https://api.github.com/repositories/' . $i . '/releases/latest');
        }

        $status = $this->checker()->check(new UpdateStatus());

        self::assertSame(UpdateErrorCode::Redirect, $status->error?->code);
        self::assertCount(3, $this->requests);
    }

    public function testATagThatIsNotAVersionIsAnErrorAndKeepsTheLastGoodRelease(): void
    {
        $this->queue[] = self::release('2.12.0');
        $this->queue[] = self::release('nightly');

        $good = $this->checker()->check(new UpdateStatus());
        $status = $this->checker()->check($good);

        self::assertSame(UpdateErrorCode::InvalidTag, $status->error?->code);
        self::assertSame('nightly', $status->error->params['tag']);
        self::assertSame('2.12.0', $status->latest, 'kept, but an error shows no banner');
    }

    public function testALinkOutsideGithubIsRefused(): void
    {
        $this->queue[] = self::release('2.12.0', url: 'https://evil.example/gwpreston16/Logbook/releases/tag/v2.12.0');

        self::assertSame(UpdateErrorCode::InvalidUrl, $this->checker()->check(new UpdateStatus())->error?->code);
    }

    public function testALinkForAnotherRepositoryNamesWhereItMoved(): void
    {
        $this->queue[] = self::redirect('https://api.github.com/repositories/1390111894/releases/latest');
        $this->queue[] = self::release('2.12.0', url: 'https://github.com/someone/Logbook2/releases/tag/v2.12.0');

        $status = $this->checker()->check(new UpdateStatus());

        self::assertSame(UpdateErrorCode::Moved, $status->error?->code);
        self::assertSame('someone/Logbook2', $status->error->params['repo']);
        self::assertNull($status->latest);
    }

    public function testTheRepositoryNameIgnoresCase(): void
    {
        $this->queue[] = self::release('2.12.0', url: 'https://github.com/GWPreston16/logbook/releases/tag/v2.12.0');

        self::assertNull($this->checker()->check(new UpdateStatus())->error);
    }

    public function testOnlyTheFourFieldsAreKeptAndTheNameIsPlainText(): void
    {
        $this->queue[] = new MockResponse((string) json_encode([
            'tag_name' => 'v2.12.0',
            'html_url' => 'https://github.com/gwpreston16/Logbook/releases/tag/v2.12.0',
            'name' => "<script>alert(1)</script>\n" . str_repeat('a', 300),
            'published_at' => 'yesterday',
            'body' => '# Notes',
            'assets' => [['browser_download_url' => 'https://example.com/x.zip']],
        ]), ['http_code' => 200]);

        $status = $this->checker()->check(new UpdateStatus());

        self::assertNull($status->error);
        self::assertNotNull($status->releaseName);
        self::assertStringStartsWith('<script>alert(1)</script> aaa', $status->releaseName, 'escaped where shown');
        self::assertSame(200, mb_strlen($status->releaseName));
        self::assertNull($status->publishedAt, 'not a date: left out');
        self::assertSame(
            ['latest', 'release_url', 'release_name', 'published_at', 'etag', 'checked_at', 'error', 'retry_at'],
            array_keys($status->toArray()),
        );
    }

    public function testAStatusRoundTripsThroughItsStoredForm(): void
    {
        $this->queue[] = new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 60']]);
        $status = $this->checker()->check(new UpdateStatus());

        self::assertEquals($status, UpdateStatus::fromArray($status->toArray()));
    }

    private function checker(): ReleaseChecker
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $headers = $options['normalized_headers'] ?? [];
            $this->requests[] = [
                'url' => $url,
                'headers' => self::headers(is_array($headers) ? $headers : []),
                'timeout' => $options['timeout'] ?? null,
                'max_duration' => $options['max_duration'] ?? null,
                'max_redirects' => $options['max_redirects'] ?? null,
            ];

            return array_shift($this->queue) ?? self::fail('An unexpected request: ' . $url);
        });

        return new ReleaseChecker(
            $http,
            Kernel::settings(['UPDATE_CHECK_REPO' => 'gwpreston16/Logbook']),
            new InstalledVersion('2.11.0'),
            $this->clock,
            new NullLogger(),
        );
    }

    private static function release(
        string $tag,
        ?string $url = null,
        ?string $etag = null,
    ): MockResponse {
        $body = json_encode([
            'tag_name' => $tag,
            'html_url' => $url ?? 'https://github.com/gwpreston16/Logbook/releases/tag/' . $tag,
            'name' => 'Logbook ' . ltrim($tag, 'v'),
            'published_at' => '2026-10-01T08:00:00Z',
        ], JSON_THROW_ON_ERROR);

        return new MockResponse($body, [
            'http_code' => 200,
            'response_headers' => $etag === null ? [] : ['ETag: ' . $etag],
        ]);
    }

    private static function redirect(string $location): MockResponse
    {
        return new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location: ' . $location]]);
    }

    /**
     * @param array<array-key, mixed> $normalized
     * @return array<string, list<string>>
     */
    private static function headers(array $normalized): array
    {
        $headers = [];
        foreach ($normalized as $name => $lines) {
            foreach (is_array($lines) ? $lines : [] as $line) {
                if (is_string($name) && is_string($line)) {
                    $headers[$name][] = substr($line, strlen($name) + 2);
                }
            }
        }

        return $headers;
    }
}
