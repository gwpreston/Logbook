<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Household;

use Logbook\Domain\Webhook\WebhookEvent;
use Logbook\Repository\WebhookDeliveryRepository;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Service\Reminder\CalendarFeed;
use Logbook\Service\Webhook\WebhookService;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\Household\Household;
use Logbook\Tests\Support\Household\LeakNeedles;
use Logbook\Tests\Support\Household\LeakSweeping;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Symfony\Component\Mime\Email;

/**
 * The leak sweep's other outputs (Phase 45.2): what the app sends rather
 * than serves, as each person of the household, scanned like the pages
 * (LeakSweepTest):
 *
 * - each person's calendar feed;
 * - their email and personal webhook: the monthly digest and reminders;
 * - their entry webhooks, after Alex logs on every vehicle: only vehicles
 *   they may see, and a trip only for those who see other drivers' trips;
 * - the server's webhook (`WEBHOOK_URL`), which gets everyone's: names
 *   and reminders, never an amount (#366) or the briefing (#377).
 */
final class LeakSweepDeliveryTest extends ReminderTestCase
{
    use ApiFixtures;
    use LeakSweeping;

    private const string SERVER_HOOK = 'https://hooks.test/logbook';

    public function testNothingSentToSomeoneShowsWhatTheyShouldNot(): void
    {
        $app = $this->createRecordingApp(['FEATURES_TRIPS' => 'true'] + self::CHANNELS);
        $household = $this->loadHousehold($app);

        $hooks = [];
        $sent = [];
        foreach (Household::people() as $person) {
            $user = $this->withEmail($app, $household->user($person), $person . '@example.test');
            $this->giveChannel($app, $user, 'personal-webhook', ['url' => 'https://hooks.test/' . $person]);
            $browser = $this->browserFor($app, $person);
            $browser->get('/settings/reminders');
            $saved = $browser->post('/settings/reminders', [
                'schedule_days' => '30',
                'schedule_distance' => '621',
                'document_days' => '30',
                'manual_days' => '7',
                'channels' => ['email', 'webhook'],
                'digest' => '1',
            ]);
            self::assertSame(303, $saved->getStatusCode(), self::body($saved));
            $hooks[$person] = $this->service($app, WebhookService::class)
                ->create($user, 'Sweep', 'https://hooks.test/entries-' . $person, WebhookEvent::cases())['webhook'];

            $token = $this->service($app, CalendarFeed::class)->issueToken($user);
            $feed = (new TestBrowser($app))->get('/calendar/' . $token . '.ics');
            self::assertSame(200, $feed->getStatusCode());
            $sent[$person]['calendar feed'] = self::body($feed);
        }

        // Each owner logs on their vehicles, and Alex a trip on the Golf, which only Jo of the others may see.
        $alex = new ApiClient($app, $this->apiKey($app, $household->user('alex')));
        $sam = new ApiClient($app, $this->apiKey($app, $household->user('sam')));
        foreach ($household->vehicles() as $key => $vehicle) {
            if ($key === 'focus') {
                continue; // sold, so archived: nothing can be logged on it
            }
            $owner = $key === 'corolla' ? $sam : $alex;
            $logged = $owner->post('/vehicles/' . $vehicle->id . '/expenses', ['category' => 'parking', 'amount' => '3']);
            self::assertSame(201, $logged->getStatusCode(), $key . ': ' . self::body($logged));
        }
        $trip = $alex->post('/vehicles/' . $household->vehicle('golf')->id . '/trips', [
            'travelled_on' => '2026-09-14',
            'from' => 'Leeds',
            'to' => 'Harrogate',
            'distance_km' => '35.4',
            'purpose' => 'Site visit',
        ]);
        self::assertSame(201, $trip->getStatusCode(), self::body($trip));

        $wrong = [];
        foreach (Household::people() as $person) {
            $visible = array_map(
                static fn (string $key): int => $household->vehicle($key)->id,
                Household::PEOPLE[$person]['vehicles'],
            );
            $deliveries = $this->service($app, WebhookDeliveryRepository::class)->listForWebhook($hooks[$person]->id);
            $kinds = [];
            foreach ($deliveries as $delivery) {
                $payload = $delivery->payload;
                $kinds[] = $payload['kind'] ?? '?';
                if (!in_array($payload['vehicle_id'] ?? null, $visible, true)) {
                    $wrong[] = "{$person}'s entry webhook is told of vehicle " . json_encode($payload['vehicle_id'] ?? null);
                }
                $sent[$person]['entry webhook ' . $delivery->id] = (string) json_encode($payload);
            }
            $model = Household::PEOPLE[$person];
            $seesTrip = in_array('golf', $model['vehicles'], true) && $model['othersTrips'];
            if (in_array('trip', $kinds, true) !== $seesTrip) {
                $wrong[] = "{$person}'s entry webhook " . ($seesTrip ? 'is not' : 'is') . " told of Alex's trip";
            }
            if (count($visible) > 0 && !in_array('expense', $kinds, true)) {
                $wrong[] = "{$person}'s entry webhook is not told of the expenses on their vehicles";
            }
        }

        $this->service($app, ScheduledTasks::class)->run();

        foreach (Household::people() as $person) {
            $mails = array_values(array_filter(
                $this->mail->sent,
                static fn (Email $e): bool => $e->getTo()[0]->getAddress() === $person . '@example.test',
            ));
            foreach ($mails as $i => $mail) {
                $where = 'email ' . $i . ' "' . $mail->getSubject() . '"';
                $sent[$person][$where] = $mail->getTextBody() . "\n" . $mail->getHtmlBody();
            }
            foreach ($this->http->to('https://hooks.test/' . $person) as $i => $request) {
                $sent[$person]['personal webhook ' . $i] = (string) json_encode($request['json']);
            }
        }
        foreach (['alex', 'sam', 'jo', 'vic'] as $person) {
            self::assertNotEmpty(array_filter(
                $sent[$person],
                static fn (string $body, string $where): bool => str_starts_with($where, 'personal webhook')
                    && str_contains($body, '"event":"digest"'),
                ARRAY_FILTER_USE_BOTH,
            ), "{$person}'s digest went out, or the sweep checked nothing");
        }

        // The server's webhook may name every vehicle (it gets every reminder), but no amount.
        $amounts = LeakNeedles::for('adm')->withFigures('adm', self::figures($app->getContainer(), $household));
        $amounts = $amounts->only(array_fill_keys(array_keys($amounts->amounts), true));
        $server = $this->http->to(self::SERVER_HOOK);
        self::assertNotEmpty($server, 'the server webhook got something');
        foreach ($server as $i => $request) {
            foreach ($amounts->find((string) json_encode($request['json'])) as $needle => $snippet) {
                $wrong[] = "the server webhook ({$i}) gets {$needle} ({$amounts->label((string) $needle)}): …{$snippet}…";
            }
        }

        $leaks = [...$wrong, ...$this->leaks($app, $household, $sent, control: false)];
        self::assertSame([], $leaks, count($leaks) . " leaks:\n" . implode("\n", $leaks));
    }
}
