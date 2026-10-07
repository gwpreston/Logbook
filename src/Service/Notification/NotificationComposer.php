<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use DateTimeImmutable;
use IntlDateFormatter;
use Logbook\Domain\FuelPrices\ListedPrice;
use Logbook\Domain\FuelPrices\PriceAlert;
use Logbook\Domain\Job\JobRun;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\User\User;
use Logbook\Service\Attention\AttentionItem;
use Logbook\Service\Attention\AttentionWording;
use Logbook\Service\Reminder\ReminderEntry;
use Logbook\Service\Reminder\ReminderWording;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Display\UserDisplayScope;
use Logbook\Support\Http\AbsoluteUrl;
use Symfony\Component\Translation\Translator;

/**
 * Words notifications for one owner: in their language, units and time zone
 * (spec.md §7.11), whatever the current request or CLI run is set to. All
 * text comes from the message catalogue (`notifications.*`).
 */
final readonly class NotificationComposer
{
    public function __construct(
        private Translator $translator,
        private UserDisplayScope $scope,
        private ReminderWording $wording,
        private AbsoluteUrl $urls,
        private AttentionWording $attention,
        private DisplayFormatter $formatter,
    ) {
    }

    /**
     * Reminders that have just become due or overdue, as one notification.
     *
     * @param non-empty-list<ReminderEntry> $entries
     * @param DateTimeImmutable $today the owner's calendar date
     */
    public function reminders(User $user, array $entries, DateTimeImmutable $today): Notification
    {
        return $this->scope->run($user, function () use ($entries, $today): Notification {
            $items = $this->items($entries, $today);
            $title = count($items) === 1
                ? $this->translator->trans('notifications.reminders.title_one', [
                    'item' => $items[0]->title,
                    'when' => $items[0]->detail,
                ])
                : $this->translator->trans('notifications.reminders.title_many', ['count' => count($items)]);

            return new Notification(
                kind: NotificationKind::Reminders,
                title: $title,
                message: $this->lines($items, 'notifications.reminders.intro'),
                url: $this->urls->route('reminders.index'),
                urgent: $this->anyOverdue($entries),
                items: $items,
                locale: $this->translator->getLocale(),
            );
        });
    }

    /**
     * Reminders that have just become due or overdue, written for each
     * group of channels (spec.md §7.11 *Reminders by category*): all of
     * them, and when they are mixed, the due and the overdue ones apart.
     *
     * @param non-empty-list<ReminderEntry> $entries
     * @param DateTimeImmutable $today the owner's calendar date
     */
    public function reminderMessages(User $user, array $entries, DateTimeImmutable $today): ReminderMessages
    {
        $overdue = array_values(array_filter(
            $entries,
            static fn (ReminderEntry $e): bool => $e->reminder->status === ReminderStatus::Overdue,
        ));
        $due = array_values(array_filter(
            $entries,
            static fn (ReminderEntry $e): bool => $e->reminder->status !== ReminderStatus::Overdue,
        ));
        $all = $this->reminders($user, $entries, $today);
        if ($overdue === [] || $due === []) {
            return new ReminderMessages($all, $due === [] ? null : $all, $overdue === [] ? null : $all);
        }

        return new ReminderMessages(
            $all,
            $this->reminders($user, $due, $today),
            $this->reminders($user, $overdue, $today),
        );
    }

    /**
     * "What's due this month": open reminders due by the end of the month,
     * overdue ones included, then (Phase 24) the *Needs attention* checks
     * on the same vehicles. Either list may be empty, not both.
     *
     * @param list<ReminderEntry> $entries
     * @param list<AttentionItem> $checks
     * @param DateTimeImmutable $today the owner's calendar date
     */
    public function digest(User $user, array $entries, DateTimeImmutable $today, array $checks = []): Notification
    {
        return $this->scope->run($user, function () use ($entries, $today, $checks): Notification {
            // Stand-alone month name ("October 2026"); the date is a calendar date, so UTC.
            $formatter = new IntlDateFormatter(
                $this->translator->getLocale(),
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                'UTC',
                null,
                'LLLL y',
            );
            $month = (string) $formatter->format($today);
            $items = $this->items($entries, $today);
            $message = $items === []
                ? $this->translator->trans('notifications.digest.nothing_due', ['month' => $month])
                : $this->lines($items, 'notifications.digest.intro', ['count' => count($items), 'month' => $month]);
            if ($checks !== []) {
                $lines = ['', $this->translator->trans('notifications.digest.attention', ['count' => count($checks)]), ''];
                foreach ($checks as $check) {
                    $lines[] = $this->translator->trans('notifications.attention_line', [
                        'line' => $this->attention->line($check),
                    ]);
                }
                $message .= "\n" . implode("\n", $lines);
            }

            return new Notification(
                kind: NotificationKind::Digest,
                title: $items === []
                    ? $this->translator->trans('notifications.digest.title_checks', [
                        'month' => $month,
                        'count' => count($checks),
                    ])
                    : $this->translator->trans('notifications.digest.title', ['month' => $month]),
                message: $message,
                url: $this->urls->route('reminders.index'),
                urgent: false,
                items: $items,
                attention: array_map(fn (AttentionItem $c): array => [
                    'vehicle_id' => $c->vehicle->id,
                    'vehicle' => $c->vehicle->name(),
                    'kind' => $c->kind->value,
                    'title' => $this->attention->title($c),
                ], $checks),
                locale: $this->translator->getLocale(),
            );
        });
    }

    public function test(User $user): Notification
    {
        return $this->scope->run($user, fn (): Notification => new Notification(
            kind: NotificationKind::Test,
            title: $this->translator->trans('notifications.test.title'),
            message: $this->translator->trans('notifications.test.message', ['name' => $user->displayName]),
            url: $this->urls->route('reminders.index'),
            locale: $this->translator->getLocale(),
        ));
    }

    /**
     * A job failed twice in a row (spec.md §7.30 *Failure alerts*), to an
     * admin, with the run's summary and a link to it.
     */
    public function jobFailed(User $user, JobRun $run): Notification
    {
        return $this->scope->run($user, fn (): Notification => new Notification(
            kind: NotificationKind::JobFailed,
            title: $this->translator->trans('notifications.job_failed.title', [
                'job' => $this->translator->trans('jobs.job.' . $run->job . '.title'),
            ]),
            message: $this->translator->trans('notifications.job_failed.message', [
                'job' => $this->translator->trans('jobs.job.' . $run->job . '.title'),
                'summary' => $run->summary ?? '',
            ]),
            url: $this->urls->route('settings.jobs.run', ['run' => (string) $run->id]),
            urgent: true,
            locale: $this->translator->getLocale(),
        ));
    }

    /**
     * Failed jobs for one admin, as one message (spec.md §7.11, Phase 36.4:
     * held through quiet hours, #253). One alone is jobFailed().
     *
     * @param non-empty-list<JobRun> $runs
     */
    public function jobsFailed(User $user, array $runs): Notification
    {
        if (count($runs) === 1) {
            return $this->jobFailed($user, $runs[0]);
        }

        return $this->scope->run($user, function () use ($runs): Notification {
            $lines = [$this->translator->trans('notifications.jobs_failed.intro'), ''];
            foreach ($runs as $run) {
                $lines[] = $this->translator->trans('notifications.jobs_failed.line', [
                    'job' => $this->translator->trans('jobs.job.' . $run->job . '.title'),
                    'summary' => $run->summary ?? '',
                ]);
            }
            $lines[] = '';
            $lines[] = $this->translator->trans('notifications.jobs_failed.outro');

            return new Notification(
                kind: NotificationKind::JobFailed,
                title: $this->translator->trans('notifications.jobs_failed.title', ['count' => count($runs)]),
                message: implode("\n", $lines),
                url: $this->urls->route('settings.jobs'),
                urgent: true,
                locale: $this->translator->getLocale(),
            );
        });
    }

    /**
     * A personal channel switched itself off after failing 5 times in a row
     * (spec.md §7.11 *Switched off after failures*).
     *
     * @param non-empty-list<string> $labels the channels' names (translation keys or product names)
     */
    public function channelOff(User $user, array $labels): Notification
    {
        return $this->scope->run($user, function () use ($labels): Notification {
            $names = array_map(fn (string $label): string => $this->translator->trans($label), $labels);

            return new Notification(
                kind: NotificationKind::ChannelOff,
                title: $this->translator->trans('notifications.channel_off.title', [
                    'count' => count($names),
                    'channel' => $names[0],
                ]),
                message: $this->translator->trans('notifications.channel_off.message', [
                    'count' => count($names),
                    'channels' => implode(', ', $names),
                ]),
                url: $this->urls->route('settings.notifications'),
                urgent: true,
                locale: $this->translator->getLocale(),
            );
        });
    }

    /**
     * Every alert of one user's that fired in one check, as one message
     * (spec.md §7.11, Phase 36.4, #253). One alone is priceAlert().
     *
     * @param non-empty-list<array{Station, PriceAlert, ListedPrice}> $alerts
     */
    public function priceAlerts(User $user, array $alerts, string $currency): Notification
    {
        if (count($alerts) === 1) {
            return $this->priceAlert($user, $alerts[0][0], $alerts[0][1], $alerts[0][2], $currency);
        }

        return $this->scope->run($user, function () use ($alerts, $currency): Notification {
            $lines = [$this->translator->trans('notifications.price_alerts.intro'), ''];
            foreach ($alerts as [$station, $alert, $listed]) {
                $lines[] = $this->translator->trans('notifications.price_alerts.line', [
                    'grade' => $this->translator->trans($alert->grade->shortLabelKey()),
                    'station' => $station->data->name,
                    'price' => $this->formatter->unitPrice($listed->price, $currency, false),
                    'below' => $this->formatter->unitPrice($alert->below, $currency, false),
                    'listed' => $this->formatter->dateTime($listed->reportedAt, IntlDateFormatter::SHORT),
                ]);
            }

            return new Notification(
                kind: NotificationKind::PriceAlert,
                title: $this->translator->trans('notifications.price_alerts.title', ['count' => count($alerts)]),
                message: implode("\n", $lines),
                url: $this->urls->route('stations.index'),
                locale: $this->translator->getLocale(),
            );
        });
    }

    /**
     * "E10 95 at Tesco Antrim is £1.359/L" (spec.md §7.34 *Price alerts*),
     * in the user's language and units.
     */
    public function priceAlert(
        User $user,
        Station $station,
        PriceAlert $alert,
        ListedPrice $listed,
        string $currency,
    ): Notification {
        return $this->scope->run($user, fn (): Notification => new Notification(
            kind: NotificationKind::PriceAlert,
            title: $this->translator->trans('notifications.price_alert.title', [
                'grade' => $this->translator->trans($alert->grade->shortLabelKey()),
                'station' => $station->data->name,
                'price' => $this->formatter->unitPrice($listed->price, $currency, false),
            ]),
            message: $this->translator->trans('notifications.price_alert.message', [
                'grade' => $this->translator->trans($alert->grade->shortLabelKey()),
                'station' => $station->data->name,
                'price' => $this->formatter->unitPrice($listed->price, $currency, false),
                'below' => $this->formatter->unitPrice($alert->below, $currency, false),
                'listed' => $this->formatter->dateTime($listed->reportedAt, IntlDateFormatter::SHORT),
            ]),
            url: $this->urls->route('stations.show', ['station' => (string) $station->id]),
            locale: $this->translator->getLocale(),
        ));
    }

    /**
     * @param list<ReminderEntry> $entries
     * @return list<NotificationItem>
     */
    private function items(array $entries, DateTimeImmutable $today): array
    {
        return array_map(fn (ReminderEntry $e): NotificationItem => new NotificationItem(
            reminderId: $e->reminder->id,
            title: $this->translator->trans('notifications.item_title', [
                'name' => $this->wording->name($e->reminder),
                'vehicle' => $e->vehicle->name(),
            ]),
            detail: $this->wording->whenWithDate($e->reminder, $today),
            status: $e->reminder->status->value,
            dueOn: $e->reminder->dueOn?->format('Y-m-d'),
        ), $entries);
    }

    /**
     * An intro line, then one "• title: detail" line per item.
     *
     * @param list<NotificationItem> $items
     * @param array<string, int|string> $params
     */
    private function lines(array $items, string $introKey, array $params = []): string
    {
        $lines = [$this->translator->trans($introKey, $params + ['count' => count($items)]), ''];
        foreach ($items as $item) {
            $lines[] = $this->translator->trans('notifications.item_line', ['title' => $item->title, 'detail' => $item->detail]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<ReminderEntry> $entries
     */
    private function anyOverdue(array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($entry->reminder->status === ReminderStatus::Overdue) {
                return true;
            }
        }

        return false;
    }
}
