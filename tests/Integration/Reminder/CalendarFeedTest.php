<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Middleware\SessionMiddleware;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;

/**
 * The iCal / webcal feed (spec.md §7.6): valid iCalendar, reachable only
 * with its secret token, revocable.
 */
final class CalendarFeedTest extends ReminderTestCase
{
    private const string NOW = '2026-09-27T10:00:00Z';

    public function testTheFeedLinkIsShownOnceAndServesTheReminders(): void
    {
        $app = $this->createApp(['APP_URL' => 'https://garage.example']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-10-09');
        $this->document($app, $golf, '2027-03-31', ComplianceType::Inspection, null, 'MOT; annual, with emissions');
        $this->schedule($app, $golf, 'Annual service', '2025-12-01');

        self::assertStringContainsString('Create a feed link', self::body($browser->get('/settings/reminders')));

        $feed = $this->issue($browser);
        self::assertMatchesRegularExpression('~^https://garage\.example/calendar/\d+-[a-f0-9]{64}\.ics$~', $feed['https']);
        self::assertSame(str_replace('https://', 'webcal://', $feed['https']), $feed['webcal']);

        $again = self::body($browser->get('/settings/reminders'));
        self::assertStringNotContainsString($feed['https'], $again, 'shown once only');
        self::assertStringContainsString('The calendar feed is on.', $again);

        // A calendar app: no cookies, no session.
        $client = new TestBrowser($app);
        $response = $client->get(self::path($feed['https']));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/calendar; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertNull($client->sessionCookie(), 'the feed never starts a session');
        self::assertFalse($response->hasHeader('Set-Cookie'));

        $ics = self::body($response);
        self::assertSame(0, preg_match("/(?<!\r)\n/", $ics), 'CRLF line endings');
        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), 'folded at 75 octets');
        }
        $lines = explode("\r\n", rtrim(str_replace("\r\n ", '', $ics)));
        self::assertSame('BEGIN:VCALENDAR', $lines[0]);
        self::assertSame('END:VCALENDAR', end($lines));
        self::assertContains('X-WR-CALNAME:Logbook reminders', $lines);
        self::assertSame(3, count(array_keys($lines, 'BEGIN:VEVENT', true)));
        self::assertContains('SUMMARY:Insurance — Volkswagen Golf', $lines);
        self::assertContains('DTSTART;VALUE=DATE:20261009', $lines);
        self::assertContains('SUMMARY:MOT\; annual\, with emissions — Volkswagen Golf', $lines, 'TEXT is escaped');
        self::assertContains('SUMMARY:Annual service — Volkswagen Golf', $lines);
        self::assertContains('DTSTART;VALUE=DATE:20261201', $lines);
        self::assertContains('URL:https://garage.example/reminders', $lines);
        self::assertContains('TRIGGER:-PT42660M', $lines, 'alarm at the 30-day lead time');
        self::assertCount(3, preg_grep('/^UID:reminder-\d+@garage\.example$/', $lines) ?: []);
    }

    public function testClosedRemindersLeaveTheFeed(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');
        $feed = $this->issue($browser);
        $browser->get('/reminders');
        $browser->post('/reminders/' . $this->onlyReminder($app)->id . '/done');

        $ics = self::body((new TestBrowser($app))->get(self::path($feed['https'])));

        self::assertStringNotContainsString('BEGIN:VEVENT', $ics);
        self::assertStringContainsString('BEGIN:VCALENDAR', $ics);
    }

    public function testUnknownResetAndRevokedTokensGetA404(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $calendar = new TestBrowser($app);

        $first = self::path($this->issue($browser)['https']);
        self::assertSame(200, $calendar->get($first)->getStatusCode());

        [$user, $secret] = explode('-', basename($first, '.ics'));
        $forged = '/calendar/' . $user . '-' . strrev($secret) . '.ics';
        self::assertSame(404, $calendar->get($forged)->getStatusCode());
        self::assertSame(404, $calendar->get('/calendar/' . ((int) $user + 1) . '-' . $secret . '.ics')->getStatusCode());
        self::assertSame(404, $calendar->get('/calendar/not-a-token.ics')->getStatusCode());

        $second = self::path($this->issue($browser)['https']);
        self::assertNotSame($first, $second);
        self::assertSame(404, $calendar->get($first)->getStatusCode(), 'a new link stops the old one working');
        self::assertSame(200, $calendar->get($second)->getStatusCode());

        $off = $browser->post('/settings/reminders/calendar', ['feed' => 'disable']);
        self::assertStringContainsString('its link no longer works', self::body($browser->follow($off)));
        self::assertSame(404, $calendar->get($second)->getStatusCode());
        self::assertSame(400, $browser->post('/settings/reminders/calendar', ['feed' => 'maybe'])->getStatusCode());
    }

    public function testFeedUrlsCarryTheSubpath(): void
    {
        $app = $this->createApp(['APP_URL' => 'https://example.net/logbook', 'APP_BASE_PATH' => '/logbook']);
        $browser = $this->signedIn($app);

        $feed = $this->issue($browser, '/logbook');

        self::assertMatchesRegularExpression('~^https://example\.net/logbook/calendar/\d+-[a-f0-9]{64}\.ics$~', $feed['https']);
        $calendar = new TestBrowser($app);
        self::assertSame(200, $calendar->get(self::path($feed['https']))->getStatusCode(), 'prefix forwarded');
        $stripped = substr(self::path($feed['https']), strlen('/logbook'));
        self::assertSame(200, $calendar->get($stripped)->getStatusCode(), 'prefix stripped by the proxy');
    }

    /**
     * Turn the feed on (or renew it) and read the URLs shown once.
     *
     * @return array{https: string, webcal: string}
     */
    private function issue(TestBrowser $browser, string $base = ''): array
    {
        $response = $browser->post($base . '/settings/reminders/calendar', ['feed' => 'issue']);
        self::assertSame($base . '/settings/reminders', $response->getHeaderLine('Location'));
        $html = self::body($browser->follow($response));
        self::assertStringContainsString('Copy the link now', $html);

        $field = static function (string $id) use ($html): string {
            $found = preg_match('~id="' . $id . '" type="text" readonly value="([^"]+)"~', $html, $m) === 1;
            self::assertTrue($found, $id . ' is shown');

            return html_entity_decode($m[1] ?? '');
        };

        return ['https' => $field('f-feed-https'), 'webcal' => $field('f-feed-webcal')];
    }

    private static function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }
}
