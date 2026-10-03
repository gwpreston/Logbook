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
            );
        });
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
        ));
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
