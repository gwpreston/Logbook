<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Logbook\Domain\User\User;
use Logbook\Repository\UserRepository;
use Logbook\Support\Calendar\CalendarEvent;
use Logbook\Support\Calendar\ICalendar;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Display\UserDisplayScope;
use Logbook\Support\Http\AbsoluteUrl;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The optional iCal / webcal feed of an owner's reminders (spec.md §7.6).
 *
 * Calendar apps cannot sign in, so the feed URL carries a secret token:
 * "{userId}-{64 hex}". Only its keyed hash (HMAC-SHA256 with
 * SESSION_SECRET, like session ids) is stored, so the URL is shown once;
 * resetting makes a new one and the old one stops working.
 */
final readonly class CalendarFeed
{
    private const string TOKEN_PATTERN = '/^([1-9][0-9]{0,18})-([a-f0-9]{64})$/';

    public function __construct(
        private ReminderSettingsStore $settings,
        private UserRepository $users,
        private ReminderService $reminders,
        private ReminderWording $wording,
        private UserDisplayScope $scope,
        private TranslatorInterface $translator,
        private AbsoluteUrl $urls,
        private AppSettings $app,
        private ClockInterface $clock,
    ) {
    }

    public function isEnabled(User $user): bool
    {
        return $this->settings->calendarTokenHash($user->id) !== null;
    }

    /**
     * Turn the feed on, or replace its URL. Returns the new token (shown once).
     */
    public function issueToken(User $user): string
    {
        $token = $user->id . '-' . bin2hex(random_bytes(32));
        $this->settings->setCalendarTokenHash($user->id, $this->hash($token));

        return $token;
    }

    public function disable(User $user): void
    {
        $this->settings->setCalendarTokenHash($user->id, null);
    }

    /**
     * The owner a token belongs to, or null for an unknown / revoked one.
     */
    public function userFor(string $token): ?User
    {
        if (preg_match(self::TOKEN_PATTERN, $token, $m) !== 1) {
            return null;
        }

        $user = $this->users->find((int) $m[1]);
        if ($user !== null && !$user->isActive()) {
            return null;
        }
        $stored = $user === null ? null : $this->settings->calendarTokenHash($user->id);

        return $stored !== null && hash_equals($stored, $this->hash($token)) ? $user : null;
    }

    /**
     * @return array{https: string, webcal: string}
     */
    public function urls(string $token): array
    {
        $https = $this->urls->route('calendar.feed', ['token' => $token]);

        return ['https' => $https, 'webcal' => (string) preg_replace('~^https?://~', 'webcal://', $https)];
    }

    /**
     * The feed: every open reminder with a date, as an all-day event with
     * an alarm at its lead time, worded in the owner's language.
     */
    public function render(User $user): string
    {
        // Their own vehicles and those shared with "Send me its reminders" (Phase 19).
        $overview = $this->reminders->overview($user, recipientOnly: true);
        $host = parse_url($this->app->url, PHP_URL_HOST);
        $domain = is_string($host) && $host !== '' ? $host : 'logbook.invalid';

        return $this->scope->run($user, function () use ($overview, $domain): string {
            $events = [];
            foreach ($overview->open as $entry) {
                $reminder = $entry->reminder;
                if ($reminder->dueOn === null) {
                    continue;
                }
                $summary = $this->translator->trans('notifications.item_title', [
                    'name' => $this->wording->name($reminder),
                    'vehicle' => $entry->vehicle->name(),
                ]);
                // Nothing relative ("in 5 days"): it would go stale in the calendar.
                $description = implode("\n", array_filter([
                    $reminder->dueKm === null ? null : $this->wording->atOdometer($reminder->dueKm),
                    $reminder->notes,
                ]));

                $events[] = new CalendarEvent(
                    uid: sprintf('reminder-%d@%s', $reminder->id, $domain),
                    date: $reminder->dueOn,
                    summary: $summary,
                    description: $description,
                    url: $this->urls->route('reminders.index'),
                    alarmDaysBefore: $reminder->leadTimeDays,
                );
            }

            return ICalendar::render($this->translator->trans('reminders.calendar.name'), $events, $this->clock->now());
        });
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->app->sessionSecret);
    }
}
