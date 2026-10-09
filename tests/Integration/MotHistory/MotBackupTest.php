<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\MotHistory;

use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Repository\IssueRepository;
use Logbook\Repository\MotTestRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Backup\BackupService;

/**
 * MOT history in backups and a user's export (spec.md §7.38 *Backups*,
 * #332): the tests, their defects (linked to issues) and their `mot`
 * readings come back from a restore on every engine; the credentials never
 * leave.
 */
final class MotBackupTest extends MotHistoryTestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null && is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function testABackupAndAUsersExportRestoreTheTestsDefectsAndReadings(): void
    {
        if (!BackupService::isAvailable()) {
            self::markTestSkipped('Needs the zip extension.');
        }
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $browser->post('/vehicles/' . $golf->id . '/mot-history/review', ['do' => 'issues']);
        $tests = $this->service($this->app, MotTestRepository::class);
        $before = $this->snapshot($golf->id);
        self::assertGreaterThan(0, $before['readings']);
        self::assertGreaterThan(0, $before['linked']);

        foreach (['create', 'createForUser'] as $how) {
            $this->file = tempnam(sys_get_temp_dir(), 'logbook-mot-') . '.zip';
            $backups = $this->service($this->app, BackupService::class);
            $how === 'create'
                ? $backups->create($this->file)
                : $backups->createForUser($this->file, $this->owner);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($this->file));
            self::assertFalse($zip->locateName('database/mot_history_secrets.json'), 'credentials never leave (#332)');
            $zip->close();

            $this->service($this->app, BackupService::class)->restore($this->file);

            self::assertSame($before, $this->snapshot($golf->id), $how);
            self::assertSame('yes', $tests->state($golf->id)->recall?->value);
            unlink($this->file);
        }
        self::assertNotNull($this->service($this->app, UserRepository::class)->findByUsername('owner'));
    }

    /**
     * @return array{tests: int, defects: int, linked: int, readings: int, issues: int}
     */
    private function snapshot(int $vehicleId): array
    {
        $tests = $this->service($this->app, MotTestRepository::class)->listForVehicle($vehicleId);
        $defects = 0;
        $linked = 0;
        foreach ($tests as $test) {
            $defects += count($test->defects);
            foreach ($test->defects as $defect) {
                $linked += $defect->issueId === null ? 0 : 1;
            }
        }
        $readings = array_filter(
            $this->service($this->app, OdometerReadingRepository::class)->listForVehicle($vehicleId),
            static fn ($reading): bool => $reading->source === OdometerSource::Mot,
        );

        return [
            'tests' => count($tests),
            'defects' => $defects,
            'linked' => $linked,
            'readings' => count($readings),
            'issues' => count($this->service($this->app, IssueRepository::class)->listForVehicle($vehicleId)),
        ];
    }
}
