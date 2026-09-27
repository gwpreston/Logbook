<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service;

use Logbook\Service\Health\HealthReport;
use Logbook\Service\Health\HealthStatus;
use PHPUnit\Framework\TestCase;

final class HealthReportTest extends TestCase
{
    public function testHealthyWhenAllChecksPass(): void
    {
        $report = new HealthReport(['app' => HealthStatus::Ok, 'database' => HealthStatus::Ok]);

        self::assertTrue($report->isHealthy());
        self::assertSame(
            ['status' => 'ok', 'checks' => ['app' => 'ok', 'database' => 'ok']],
            $report->toArray(),
        );
    }

    public function testAnyFailingCheckFailsTheReport(): void
    {
        $report = new HealthReport(['app' => HealthStatus::Ok, 'database' => HealthStatus::Failing]);

        self::assertFalse($report->isHealthy());
        self::assertSame('failing', $report->toArray()['status']);
    }
}
