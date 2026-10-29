<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use Doctrine\DBAL\Connection;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Notification\ReminderNotifier;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Cache\RequestReads;
use Logbook\Tests\Support\BriefingTestCase;
use Logbook\Tests\Support\QueryCounter;

/**
 * What the monthly digest costs the `digest` job (spec.md §7.11, §8 *Page
 * budgets*, Phase 54.3, K151): each table is read once for all the user's
 * vehicles, so ten vehicles cost the job what one does, and the digest says
 * the same as when every step read for itself.
 */
final class DigestQueryCountTest extends BriefingTestCase
{
    public function testTenVehiclesCostTheDigestNoMoreQueriesThanOne(): void
    {
        [$oneQueries, $oneText] = $this->digestOf(1);
        [$tenQueries, $tenText] = $this->digestOf(10);

        self::assertGreaterThan(0, $oneQueries, 'the counter counts');
        self::assertLessThanOrEqual(
            $oneQueries + 2,
            $tenQueries,
            'a query per vehicle would add nine or more (one vehicle ' . $oneQueries . ', ten ' . $tenQueries . ')',
        );
        self::assertStringContainsString('Model9: ', $tenText);
        self::assertStringContainsString('Model0: ', $oneText);
    }

    public function testTheDigestSaysTheSameWithAndWithoutTheSharedReads(): void
    {
        $shared = $this->digestOf(3)[1];
        $this->service($this->app, RequestReads::class)->switchOff();
        $this->resetDigest();
        $this->mail->sent = [];
        $this->service($this->app, ReminderNotifier::class)->digest($this->owner($this->app));

        self::assertSame($shared, $this->digestText());
    }

    public function testNothingStaysRememberedWhenTheDigestEnds(): void
    {
        $this->digestOf(2);

        self::assertFalse($this->service($this->app, RequestReads::class)->isActive());
    }

    /**
     * Queries the digest job costs the owner of $vehicles vehicles, and the
     * text it sends them.
     *
     * @return array{int, string}
     */
    private function digestOf(int $vehicles): array
    {
        $this->start();
        $counter = QueryCounter::install($this->app);
        // The app has read the database already; reconnect so the counter sees every statement.
        $this->service($this->app, Connection::class)->close();
        for ($i = 0; $i < $vehicles; $i++) {
            $this->monthlyVehicle($this->car('Model' . $i));
        }

        $notifier = $this->service($this->app, ReminderNotifier::class);
        $owner = $this->owner($this->app);
        $sent = null;
        $queries = $counter->during(static function () use ($notifier, $owner, &$sent): void {
            $sent = $notifier->digest($owner);
        });
        self::assertTrue($sent, 'the digest was sent');

        return [$queries, $this->digestText()];
    }

    private function monthlyVehicle(Vehicle $vehicle): void
    {
        $months = ['2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02',
            '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'];
        $this->monthlyReadings($vehicle, $months, 10000, 913);
        foreach ($months as $i => $month) {
            $this->spend($vehicle, $month . '-12', (string) (40 + $i * 13) . '.125');
        }
        $this->insurance($vehicle, '2026-10-20', '300');
    }

    private function resetDigest(): void
    {
        // An earlier month than this one: the digest is due again.
        $this->service($this->app, ReminderSettingsStore::class)->markDigestSent($this->owner($this->app)->id, '2000-01');
    }
}
