<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\MotHistory;

use Logbook\Domain\MotHistory\OdometerState;
use Logbook\Service\MotHistory\Uk\DvsaParser;
use PHPUnit\Framework\TestCase;

/**
 * Real DVSA answers, when a maintainer has recorded some with
 * bin/record-mot-history.php: every vehicle and every dated test parses,
 * and every read odometer gives a reading. Skipped when there are none
 * (the API needs credentials).
 */
final class DvsaRecordedTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../../Fixtures/mot-history/';

    public function testEveryRecordedVehicleParses(): void
    {
        $files = glob(self::DIR . 'recorded-vehicle-*.json') ?: [];
        if ($files === []) {
            self::markTestSkipped('No recorded answers: run bin/record-mot-history.php with credentials.');
        }
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            $vehicle = DvsaParser::vehicle($data);
            self::assertNotNull($vehicle, basename($file));
            $raw = is_array($data['motTests'] ?? null) ? $data['motTests'] : [];
            self::assertSame(count($raw), count($vehicle->tests) + $vehicle->undated, basename($file));
            foreach ($vehicle->tests as $test) {
                if ($test->odometerState === OdometerState::Read) {
                    self::assertNotNull($test->odometerKm, basename($file) . ' ' . $test->number);
                }
            }
        }
    }

    public function testTheRecordedBulkListHasItsShape(): void
    {
        if (!is_file(self::DIR . 'recorded-bulk-download.json')) {
            self::markTestSkipped('No recorded bulk list: run bin/record-mot-history.php with credentials.');
        }
        $data = json_decode((string) file_get_contents(self::DIR . 'recorded-bulk-download.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayHasKey('bulk', $data);
    }
}
