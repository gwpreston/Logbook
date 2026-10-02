<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Kernel;
use Logbook\Tests\Support\AppTestCase;

final class HealthEndpointTest extends AppTestCase
{
    public function testReportsOkWhenTheDatabaseIsReachable(): void
    {
        $response = $this->get($this->createApp(), '/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            [
                'status' => 'ok',
                'version' => Kernel::version(),
                'checks' => ['app' => 'ok', 'database' => 'ok'],
                // Phase 28.1: never run yet, so stale, and still 200.
                'scheduler' => ['last_pass' => null, 'stale' => true],
            ],
            json_decode(self::body($response), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testReports503WithoutLeakingDetailsWhenTheDatabaseIsDown(): void
    {
        $app = $this->createApp([
            'TEST_DB_DRIVER' => 'pgsql',
            'TEST_DB_HOST' => '127.0.0.1',
            'TEST_DB_PORT' => '1',
            'TEST_DB_PASSWORD' => 'must-not-leak',
        ]);

        $response = $this->get($app, '/health');
        $body = self::body($response);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            ['status' => 'failing', 'version' => Kernel::version(), 'checks' => ['app' => 'ok', 'database' => 'failing']],
            json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString('must-not-leak', $body);
        self::assertStringNotContainsString('127.0.0.1', $body);
    }
}
