<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Notification\Outbound;

use Logbook\Service\Notification\Channel\HttpDelivery;
use Logbook\Service\Notification\Outbound\HostBreaker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The per-run circuit breaker (spec.md §7.11 *Unreachable services in a
 * run*, #264): a host that failed 3 times without answering is skipped for
 * the rest of the run, as a refusal (never counted); an HTTP error is an
 * answer; outside a run nothing is skipped.
 */
final class HostBreakerTest extends TestCase
{
    public function testAHostThatStopsAnsweringIsSkippedForTheRun(): void
    {
        $breaker = new HostBreaker();
        $breaker->arm();
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            $calls++;

            throw new TransportException('Idle timeout reached');
        });

        for ($i = 0; $i < 3; $i++) {
            $result = HttpDelivery::post($http, 'ntfy', 'https://down.test/topic', [], $breaker);
            self::assertFalse($result->refused, 'tried and failed');
        }
        $skipped = HttpDelivery::post($http, 'ntfy', 'https://DOWN.test:443/other', [], $breaker);

        self::assertSame(3, $calls, 'the fourth send made no request');
        self::assertFalse($skipped->delivered);
        self::assertTrue($skipped->refused, 'shown, never counted');
        self::assertSame('notifications.reply.skipped_unreachable', $skipped->error);
        self::assertFalse($breaker->skips('https://up.test/'), 'other hosts are tried');
        self::assertFalse($breaker->skips('http://down.test/'), 'another port is another host');

        $breaker->disarm();
        self::assertFalse($breaker->skips('https://down.test/topic'), 'the next run starts afresh');
    }

    public function testAnHttpErrorIsAnAnswer(): void
    {
        $breaker = new HostBreaker();
        $breaker->arm();
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));

        for ($i = 0; $i < 4; $i++) {
            HttpDelivery::post($http, 'ntfy', 'https://busy.test/', [], $breaker);
        }

        self::assertSame(4, $http->getRequestsCount());
        self::assertFalse($breaker->skips('https://busy.test/'));
    }

    public function testNothingIsSkippedOutsideARun(): void
    {
        $breaker = new HostBreaker();
        foreach ([1, 2, 3, 4] as $ignored) {
            $breaker->unanswered('https://down.test/');
        }

        self::assertFalse($breaker->skips('https://down.test/'), 'a test or a check is never skipped');
    }
}
