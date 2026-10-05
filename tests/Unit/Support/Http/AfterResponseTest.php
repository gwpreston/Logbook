<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Http;

use Logbook\Support\Http\AfterResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Work queued until the response has gone (spec.md §7.9 *Forgotten
 * password*): it runs in order, once; one failing task is logged and the
 * rest still run; the answer it follows is marked to arrive complete.
 */
final class AfterResponseTest extends TestCase
{
    public function testTasksRunInOrderOnceAndAFailureDoesNotStopTheRest(): void
    {
        $log = new TestHandler();
        $after = new AfterResponse(new Logger('test', [$log]));
        $ran = [];
        $after->defer(static function () use (&$ran): void {
            $ran[] = 'first';
        });
        $after->defer(static fn () => throw new RuntimeException('smtp down'));
        $after->defer(static function () use (&$ran): void {
            $ran[] = 'third';
        });
        self::assertSame(3, $after->pending());

        $after->run();
        $after->run();

        self::assertSame(['first', 'third'], $ran);
        self::assertSame(0, $after->pending());
        self::assertTrue($log->hasErrorThatContains('Work after the response failed'));
    }

    public function testTheAnswerCarriesItsLengthAndClosesTheConnection(): void
    {
        $response = (new ResponseFactory())->createResponse();
        $response->getBody()->write('If that matches an account…');

        $marked = AfterResponse::completeBeforeWork($response);

        self::assertSame('close', $marked->getHeaderLine('Connection'));
        self::assertSame((string) strlen('If that matches an account…'), $marked->getHeaderLine('Content-Length'));
    }
}
