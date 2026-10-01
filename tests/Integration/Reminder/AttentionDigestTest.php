<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AttentionHiddenRepository;
use Logbook\Domain\Attention\AttentionKind;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Service\Sharing\SharingService;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\Mime\Email;

/**
 * The monthly digest's *Needs attention* section (spec.md §7.11, §7.24;
 * decided 2026-10-01): the recipient's *Check* items after the due
 * reminders, sent in a month with checks and nothing due, nothing at all
 * with neither, never a check the recipient could not fix or has hidden,
 * and an `attention` list in the webhook's JSON. "Today" is 1 Oct 2026, the
 * first run of the month.
 */
final class AttentionDigestTest extends ReminderTestCase
{
    private const string NOW = '2026-10-01T07:00:00Z';

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;

    public function testChecksFollowWhatIsDue(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $this->document($this->app, $golf, '2026-10-20');
        $this->backwardsReading($golf);

        $this->runTasks();

        $digest = $this->onlyDigest();
        self::assertSame('Due in October 2026', $digest->getSubject());
        $text = (string) $digest->getTextBody();
        self::assertStringContainsString('Insurance — Volkswagen Golf', $text);
        self::assertStringContainsString("One thing needs attention:\n\n• Volkswagen Golf: Reading on 1 Jun 2026", $text);
        self::assertLessThan(strpos($text, 'needs attention'), strpos($text, 'Insurance'), 'due work first');
        self::assertStringNotContainsString('Renew', $text, 'Now items are the due reminders already');

        $hook = $this->http->to('https://hooks.test');
        $json = end($hook)['json'] ?? [];
        self::assertSame('digest', $json['event'] ?? null);
        self::assertIsArray($json['items'] ?? null);
        self::assertCount(1, $json['items']);
        self::assertSame([[
            'vehicle_id' => $golf->id,
            'vehicle' => 'Volkswagen Golf',
            'kind' => 'reading',
            'title' => 'Reading on 1 Jun 2026 (5,592 mi) is lower than the one before',
        ]], $json['attention']);
    }

    public function testAMonthWithChecksAndNothingDueStillSends(): void
    {
        $this->start();
        $this->backwardsReading($this->vehicle($this->app));

        $this->runTasks();

        $digest = $this->onlyDigest('October 2026: one thing needs attention');
        $text = (string) $digest->getTextBody();
        self::assertStringStartsWith('Nothing is due in October 2026.', $text);
        self::assertStringContainsString('is lower than the one before', $text);
    }

    public function testATrendOrCostCheckIsALineToo(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $fuel = $this->service($this->app, FuelService::class);
        $prices = [
            '2026-09-02' => '1.400',
            '2026-09-08' => '1.400',
            '2026-09-12' => '14.000',
            '2026-09-22' => '1.400',
            '2026-09-26' => '1.400',
        ];
        foreach ($prices as $date => $price) {
            $km = (string) (10000 + 500 * (int) substr($date, 8));
            $fuel->create(
                $golf,
                new FuelEntryData(new DateTimeImmutable($date . 'T08:00:00Z'), $km, Fuel::Petrol, '30', $price, '0'),
            );
        }

        $this->runTasks();

        $text = (string) $this->onlyDigest('October 2026: one thing needs attention')->getTextBody();
        self::assertStringContainsString(
            '• Volkswagen Golf: Fill-up on 12 Sept 2026: £14.00/L, about 10× your usual £1.40/L',
            $text,
        );
        $hook = $this->http->to('https://hooks.test');
        $json = end($hook)['json'] ?? [];
        self::assertIsArray($json['attention'] ?? null);
        self::assertIsArray($json['attention'][0] ?? null);
        self::assertSame('fuel_price', $json['attention'][0]['kind'] ?? null);
    }

    public function testNothingDueAndNothingToCheckSendsNothing(): void
    {
        $this->start();
        $this->vehicle($this->app);

        $this->runTasks();

        self::assertSame([], $this->digests());
    }

    public function testAHiddenCheckIsLeftOut(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $reading = $this->backwardsReading($golf);
        $html = self::body($this->browser->get('/vehicles/' . $golf->id));
        preg_match('~name="fingerprint" value="([0-9a-f]{64})"~', $html, $m);
        $this->service($this->app, AttentionHiddenRepository::class)->hide(
            $this->owner($this->app)->id,
            $golf->id,
            AttentionKind::Reading,
            $reading,
            $m[1] ?? '',
            new DateTimeImmutable(self::NOW),
        );

        $this->runTasks();

        self::assertSame([], $this->digests(), 'nothing due, the one check hidden');
    }

    public function testAViewRecipientGetsTheDueWorkButNoChecks(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $this->document($this->app, $golf, '2026-10-20');
        $this->backwardsReading($golf);
        $this->createMember($this->app, 'viewer');
        $sharing = $this->service($this->app, SharingService::class);
        self::assertNull($sharing->add($golf, 'viewer', ShareLevel::View, true, true));
        $this->browserFor($this->app, 'viewer')->get('/');
        $this->saveChannels($this->browserFor($this->app, 'viewer'), 'viewer@example.com');

        $this->runTasks();

        $viewer = array_values(array_filter(
            $this->digests(),
            static fn (Email $e): bool => $e->getTo()[0]->getAddress() === 'viewer@example.com',
        ));
        self::assertCount(1, $viewer);
        $text = (string) $viewer[0]->getTextBody();
        self::assertStringContainsString('Insurance — Volkswagen Golf', $text);
        self::assertStringNotContainsString('needs attention', $text);
    }

    private function start(): void
    {
        $this->app = $this->createRecordingApp(self::CHANNELS);
        $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
        $this->saveChannels($this->browser, 'pat@example.com');
    }

    private function saveChannels(TestBrowser $browser, string $email): void
    {
        $browser->get('/settings/reminders');
        $response = $browser->post('/settings/reminders', [
            'schedule_days' => '30',
            'schedule_distance' => '621',
            'document_days' => '30',
            'manual_days' => '7',
            'channels' => ['email', 'webhook'],
            'email' => $email,
            'digest' => '1',
        ]);
        self::assertSame(303, $response->getStatusCode());
    }

    /**
     * Two readings, the second lower: one implausible-reading check.
     *
     * @return int the flagged reading's id
     */
    private function backwardsReading(Vehicle $vehicle): int
    {
        $odometer = $this->service($this->app, OdometerService::class);
        $odometer->create(
            $vehicle,
            new OdometerReadingData('10000', new DateTimeImmutable('2026-05-01T09:00:00Z', new DateTimeZone('UTC'))),
        );

        return $odometer->create(
            $vehicle,
            new OdometerReadingData('9000', new DateTimeImmutable('2026-06-01T09:00:00Z', new DateTimeZone('UTC'))),
        )->id;
    }

    private function runTasks(): void
    {
        $this->service($this->app, ScheduledTasks::class)->run();
    }

    /**
     * @return list<Email>
     */
    private function digests(): array
    {
        return array_values(array_filter(
            $this->mail->sent,
            static fn (Email $e): bool => str_starts_with((string) $e->getSubject(), 'Due in')
                || str_contains((string) $e->getSubject(), 'needs attention')
                || str_contains((string) $e->getSubject(), 'need attention'),
        ));
    }

    private function onlyDigest(?string $subject = null): Email
    {
        $digests = $this->digests();
        self::assertCount(1, $digests);
        if ($subject !== null) {
            self::assertSame($subject, $digests[0]->getSubject());
        }

        return $digests[0];
    }
}
