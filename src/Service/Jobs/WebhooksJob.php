<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use DateTimeImmutable;
use Logbook\Domain\User\User;
use Logbook\Domain\Webhook\Webhook;
use Logbook\Domain\Webhook\WebhookDelivery;
use Logbook\Repository\UserRepository;
use Logbook\Repository\WebhookDeliveryRepository;
use Logbook\Repository\WebhookRepository;
use Logbook\Service\Notification\NotificationComposer;
use Logbook\Service\Notification\NotificationDispatcher;
use Logbook\Service\Notification\Outbound\HostBreaker;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Webhook\WebhookSender;
use Logbook\Support\Config\AppSettings;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * `webhooks` (spec.md §7.20 *Webhooks*, §7.30; Phase 39.3): every pass,
 * after `reminders`. Sends the deliveries that are due, signed, to
 * destinations §7.11's policy allows; a failed one is tried again after 1
 * minute, 5 minutes, 30 minutes, 2 hours and 6 hours (minimums: the next
 * pass after, #291), then given up. 50 failed attempts in a row pause the
 * webhook (#293); its user is told once, through every usable channel,
 * after their quiet hours (#294). Rows queued over 7 days ago are removed.
 *
 * `WEBHOOKS_ENABLED=false` or `API_ENABLED=false` sends nothing (queued
 * deliveries wait). A disabled user's deliveries wait unsent.
 */
final readonly class WebhooksJob implements Job
{
    public const string NAME = 'webhooks';
    /** Deliveries one run sends at most; the rest go with the next pass. */
    public const int BATCH = 500;

    public function __construct(
        private WebhookRepository $webhooks,
        private WebhookDeliveryRepository $deliveries,
        private UserRepository $users,
        private WebhookSender $sender,
        private NotificationComposer $composer,
        private NotificationDispatcher $dispatcher,
        private ReminderSettingsStore $preferences,
        private AppSettings $settings,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
        private ?HostBreaker $breaker = null,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function interval(): int
    {
        return 0;
    }

    public function run(JobContext $context): JobResult
    {
        if (!$this->settings->webhooksEnabled || !$this->settings->apiEnabled) {
            return JobResult::ok($this->translator->trans('jobs.summary.webhooks_off'));
        }
        $now = $this->clock->now();
        $counts = ['sent' => 0, 'failed' => 0, 'given_up' => 0, 'paused' => 0, 'told' => 0, 'removed' => 0];
        $errors = 0;

        /** @var array<int, Webhook|null> $webhooks */
        $webhooks = [];
        /** @var array<int, User|null> $owners */
        $owners = [];
        foreach ($this->deliveries->due($now, self::BATCH) as $delivery) {
            if ($context->cancelled()) {
                break;
            }
            $webhook = array_key_exists($delivery->webhookId, $webhooks)
                ? $webhooks[$delivery->webhookId]
                : ($webhooks[$delivery->webhookId] = $this->webhooks->findById($delivery->webhookId));
            if ($webhook === null || $webhook->isPaused()) {
                continue;
            }
            $owner = array_key_exists($webhook->userId, $owners)
                ? $owners[$webhook->userId]
                : ($owners[$webhook->userId] = $this->users->find($webhook->userId));
            if ($owner === null || !$owner->isActive()) {
                continue;
            }
            // A host that stopped answering earlier in this run: left for the next pass, no attempt used.
            if ($this->breaker?->skips($webhook->url) === true) {
                continue;
            }
            try {
                $paused = $this->deliver($webhook, $owner, $delivery, $counts);
            } catch (Throwable $e) {
                $errors++;
                $context->logger->error('Webhook {webhook} delivery {delivery} failed: {message}', [
                    'webhook' => $webhook->id,
                    'delivery' => $delivery->id,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
                continue;
            }
            if ($paused) {
                // Its other deliveries wait for *Resume* (#293).
                $webhooks[$webhook->id] = null;
                $counts['paused']++;
                $context->logger->warning('Webhook {webhook} of user {user} paused after {count} failed attempts in a row.', [
                    'webhook' => $webhook->id,
                    'user' => $webhook->userId,
                    'count' => Webhook::PAUSE_AFTER,
                ]);
            }
        }

        try {
            $counts['told'] = $this->tellPaused($now);
        } catch (Throwable $e) {
            $errors++;
            $context->logger->error('Telling users about paused webhooks failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
        $counts['removed'] = $this->deliveries->deleteQueuedBefore($now->modify('-' . WebhookDelivery::KEEP_DAYS . ' days'));

        $summary = $this->translator->trans('jobs.summary.webhooks', $counts);

        return $errors > 0 ? JobResult::partial($summary, $counts) : JobResult::ok($summary, $counts);
    }

    /**
     * One attempt at one delivery.
     *
     * @param array<string, int> $counts
     * @return bool whether this attempt paused the webhook
     */
    private function deliver(
        Webhook $webhook,
        User $owner,
        WebhookDelivery $delivery,
        array &$counts,
    ): bool {
        $attempts = $delivery->attempts + 1;
        // The time of this request, not the run's start: a long run must not sign with a stale `t`.
        $now = $this->clock->now();
        $result = $this->sender->send($webhook, $owner, $delivery->payload, $now);
        if ($result->delivered) {
            $this->deliveries->markDelivered($delivery->id, $attempts, $now);
            $this->webhooks->recordSuccess($webhook->id, $now);
            $counts['sent']++;

            return false;
        }

        $next = WebhookDelivery::retryAt($attempts, $now);
        $this->deliveries->markFailed($delivery->id, $attempts, $next);
        $counts[$next === null ? 'given_up' : 'failed']++;

        // A destination the policy refuses is no fault of the receiver's: it never counts (§7.11).
        return $this->webhooks->recordFailure($webhook->id, (string) $result->error, !$result->refused, $now);
    }

    /**
     * Tell each user whose webhook paused itself, once, through every
     * usable channel, outside their quiet hours (#294).
     */
    private function tellPaused(DateTimeImmutable $now): int
    {
        $told = 0;
        foreach ($this->webhooks->noticePending() as $webhook) {
            $user = $this->users->find($webhook->userId);
            if ($user === null || !$user->isActive()) {
                continue;
            }
            $preferences = $this->preferences->notificationPreferences($user->id);
            if ($preferences->quiet?->contains($now, $user->preferences->timeZone()) === true) {
                continue;
            }
            $this->dispatcher->dispatch(
                $this->composer->webhookPaused($user, $webhook->name),
                Recipient::of($user),
                $preferences,
            );
            $this->webhooks->clearNotice($webhook->id);
            $told++;
        }

        return $told;
    }
}
