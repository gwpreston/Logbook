<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use Logbook\Repository\UserRepository;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Service\Scheduler\TaskSummary;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\Mime\Email;

/**
 * The monthly digest's default (spec.md §7.11, Phase 21.1): on for users
 * created by setup or an invitation, unchanged for everyone from before.
 */
final class DigestDefaultTest extends ReminderTestCase
{
    private const string NOW = '2026-10-01T07:00:00Z';

    public function testSetupCreatesAUserWithTheDigestOn(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $browser = $this->runSetup($app);

        self::assertTrue($this->digestOf($app, 'owner'));
        $card = self::body($browser->get('/settings/reminders'));
        self::assertMatchesRegularExpression('/name="digest" value="1" checked/', $card);
        self::assertStringContainsString(
            'It is sent only when a channel is set up and something is due or needs attention.',
            $card,
        );

        $this->document($app, $this->vehicle($app), '2026-10-25');
        $this->runTasks($app);
        self::assertCount(1, $this->digests(), 'the first run of the month sends it');
    }

    public function testAnInvitedUserStartsWithTheDigestOn(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $admin->get('/settings/users');
        $page = self::body($admin->post('/settings/users', ['username' => 'partner', 'display_name' => 'Sam']));
        $link = Html::element(Html::document($page), '#f-invite-link')->getAttribute('value');
        self::assertNotNull($link);

        $guest = new TestBrowser($app);
        $path = (string) parse_url($link, PHP_URL_PATH);
        $guest->get($path);
        $done = $guest->post($path, [
            'display_name' => 'Sam',
            'password' => 'partner-password',
            'password_confirm' => 'partner-password',
            'units' => 'metric',
            'currency' => 'GBP',
            'locale' => 'en_GB',
            'timezone' => 'Europe/London',
        ]);
        self::assertSame(303, $done->getStatusCode(), self::body($done));

        self::assertTrue($this->digestOf($app, 'partner'));
    }

    public function testAUserFromBeforeWithoutAChoiceKeepsItOff(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->ownerFromBefore21($app);
        $this->document($app, $this->vehicle($app), '2026-10-25');

        self::assertFalse($this->digestOf($app, 'owner'));
        $card = self::body($browser->get('/settings/reminders'));
        self::assertDoesNotMatchRegularExpression('/name="digest" value="1" checked/', $card);
        $this->runTasks($app);
        self::assertSame([], $this->digests());
    }

    public function testAnExplicitChoiceIsNeverChanged(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $browser = $this->runSetup($app);

        $saved = $browser->post('/settings/reminders', [
            'schedule_days' => '30',
            'schedule_distance' => '621',
            'document_days' => '30',
            'manual_days' => '7',
            'channels' => ['email'],
            'email' => '',
        ]);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));
        self::assertFalse($this->digestOf($app, 'owner'), 'unticked is stored');

        $this->document($app, $this->vehicle($app), '2026-10-25');
        $this->runTasks($app);
        self::assertSame([], $this->digests());
    }

    public function testTheDigestOnWithoutAChannelSendsNothingAndFailsNothing(): void
    {
        $app = $this->createRecordingApp(self::NO_CHANNELS);
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $this->runSetup($app);
        $this->document($app, $this->vehicle($app), '2026-10-25');

        $summary = $this->runTasks($app);
        self::assertSame(0, $summary->digestsSent);
        self::assertSame([], $this->mail->sent);
        self::assertSame([], $this->http->requests);
    }

    /**
     * First-run setup through the form, as "owner".
     *
     * @param App<ContainerInterface> $app
     */
    private function runSetup(App $app): TestBrowser
    {
        $browser = new TestBrowser($app);
        $browser->get('/setup');
        $response = $browser->post('/setup', [
            'username' => 'owner',
            'display_name' => 'Pat',
            'password' => self::PASSWORD,
            'password_confirm' => self::PASSWORD,
            'units' => 'metric',
            'currency' => 'GBP',
            'locale' => 'en_GB',
            'timezone' => 'Europe/London',
        ]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));

        return $browser;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function digestOf(App $app, string $username): bool
    {
        $user = $this->service($app, UserRepository::class)->findByUsername($username);
        self::assertNotNull($user);

        return $this->service($app, ReminderSettingsStore::class)->notificationPreferences($user->id)->digest;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function runTasks(App $app): TaskSummary
    {
        return $this->service($app, ScheduledTasks::class)->run();
    }

    /**
     * @return list<Email>
     */
    private function digests(): array
    {
        return array_values(array_filter(
            $this->mail->sent,
            static fn (Email $e): bool => str_starts_with((string) $e->getSubject(), 'Due in'),
        ));
    }
}
