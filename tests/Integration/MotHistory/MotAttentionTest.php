<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\MotHistory;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Odometer\OdometerService;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * MOT history in *Needs attention* (spec.md §7.24, §7.38): an outstanding
 * recall is a *Now* item with no *Hide*, gone when DVSA stops reporting
 * it (#325, #329); a reading of the owner's that disagrees with an MOT's
 * says which is which, and *Fix* opens the owner's reading.
 */
final class MotAttentionTest extends MotHistoryTestCase
{
    public function testAnOutstandingRecallIsANowItemUntilDvsaSaysOtherwise(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $viewer = $this->shareWith($golf, ShareLevel::View);

        $overview = (string) $browser->get('/vehicles/' . $golf->id)->getBody();
        self::assertStringContainsString('Outstanding recall on AB12 CDE', $overview);
        self::assertStringContainsString('/vehicles/' . $golf->id . '/mot-history', $overview);
        self::assertStringContainsString('Outstanding recall on AB12 CDE', (string) $viewer->get('/vehicles/' . $golf->id)->getBody());

        $this->answer = fn (): MockResponse => $this->withRecall('No');
        $browser->post('/vehicles/' . $golf->id . '/mot-history/fetch', []);

        self::assertStringNotContainsString('Outstanding recall', (string) $browser->get('/vehicles/' . $golf->id)->getBody());
    }

    public function testNoRecallItemWithTheProviderOff(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $this->connection($this->app)->executeStatement("DELETE FROM settings WHERE name = 'mot_history'");

        self::assertStringNotContainsString('Outstanding recall', (string) $browser->get('/vehicles/' . $golf->id)->getBody());
    }

    public function testAnOwnersReadingBelowAnEarlierMotSaysWhichIsWhich(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        // After the 15 Feb 2026 MOT at 43,961 mi, the owner logs 41,200 mi.
        $ours = $this->ownReading($golf, '66304.973', '2026-03-02T12:00:00Z');

        $overview = (string) $browser->get('/vehicles/' . $golf->id)->getBody();

        self::assertStringContainsString('Your reading on 2 Mar 2026 (41,200 mi) is lower than the MOT on 15 Feb 2026 (43,961 mi)', $overview);
        self::assertStringContainsString('/vehicles/' . $golf->id . '/odometer/' . $ours . '/edit', $overview, 'Fix opens the owner\'s reading');
    }

    public function testAnOwnersReadingAboveALaterMotIsTheOneToFix(): void
    {
        $this->start();
        $golf = $this->golf();
        // Before the 14 Feb 2025 MOT at 41,950 mi, the owner logged 45,000 mi.
        $ours = $this->ownReading($golf, '72420.480', '2025-01-10T12:00:00Z');
        $browser = $this->fetch($golf);

        $overview = (string) $browser->get('/vehicles/' . $golf->id)->getBody();

        self::assertStringContainsString('Your reading on 10 Jan 2025 (45,000 mi) is higher than the MOT on 14 Feb 2025 (41,950 mi)', $overview);
        self::assertStringContainsString('/vehicles/' . $golf->id . '/odometer/' . $ours . '/edit', $overview);
        $motIds = array_map(
            static fn ($r): int => $r->id,
            array_filter(
                $this->service($this->app, OdometerReadingRepository::class)->listForVehicle($golf->id),
                static fn ($r): bool => $r->source === OdometerSource::Mot,
            ),
        );
        foreach ($motIds as $id) {
            self::assertStringNotContainsString('/odometer/' . $id . '/edit', $overview, 'never the MOT\'s');
        }
    }

    public function testAnMotReadingOpensItsPageNotAForm(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $mot = array_values(array_filter(
            $this->service($this->app, OdometerReadingRepository::class)->listForVehicle($golf->id),
            static fn ($r): bool => $r->source === OdometerSource::Mot,
        ))[0];

        $response = $browser->get('/vehicles/' . $golf->id . '/odometer/' . $mot->id . '/edit');

        self::assertSame(303, $response->getStatusCode());
        self::assertStringEndsWith('/vehicles/' . $golf->id . '/mot-history', $response->getHeaderLine('Location'));
        self::assertStringContainsString('MOT', (string) $browser->get('/vehicles/' . $golf->id . '/odometer')->getBody());
    }

    private function ownReading(Vehicle $vehicle, string $km, string $utc): int
    {
        return $this->service($this->app, OdometerService::class)->create(
            $vehicle,
            new OdometerReadingData($km, new DateTimeImmutable($utc, new DateTimeZone('UTC'))),
        )->id;
    }

    private function withRecall(string $state): MockResponse
    {
        $data = json_decode((string) file_get_contents(self::FIXTURES . 'vehicle-with-tests.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $data['hasOutstandingRecall'] = $state;

        return new MockResponse((string) json_encode($data));
    }
}
