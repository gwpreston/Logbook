<?php

declare(strict_types=1);

namespace Logbook\Service\Webhook;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Webhook\WebhookEvent;
use Logbook\Domain\Webhook\WebhookKind;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Repository\WebhookDeliveryRepository;
use Logbook\Repository\WebhookRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Demo\DemoMode;
use Logbook\Service\Demo\DemoRestriction;
use Logbook\Support\Config\AppSettings;
use Psr\Clock\ClockInterface;

/**
 * Queues entry-webhook deliveries (spec.md §7.20 *Webhooks*, Phase 39.3).
 * The services that change entries call it once per change, inside the
 * change's transaction, so every path (a form, an import, the API, an Ask
 * draft, MCP) queues and a change that rolls back queues nothing. The
 * `webhooks` job sends what is queued.
 *
 * Who is told: every user who may `View` the vehicle (its owner and its
 * shares; a disabled user may not), through each of their webhooks that
 * takes the event. A trip only reaches those who may see it (#302). Cost
 * kinds go to users without *Can see costs* too (#295): the payload is ids
 * and links, and the receiver's fetch applies the rule.
 *
 * Nothing is queued while `WEBHOOKS_ENABLED` is off or a demo is running,
 * and nothing when no user has a webhook (one cheap query).
 */
final readonly class WebhookEvents
{
    public function __construct(
        private WebhookRepository $webhooks,
        private WebhookDeliveryRepository $deliveries,
        private VehicleShareRepository $shares,
        private VehicleRepository $vehicles,
        private UserRepository $users,
        private VehicleAccess $access,
        private AppSettings $settings,
        private ClockInterface $clock,
        private ?DemoMode $demo = null,
    ) {
    }

    /**
     * An entry was created, edited or deleted.
     *
     * @param int|null $tripAuthor for a trip, who logged it: only they and those who see everyone's trips are told
     */
    public function entry(
        Vehicle $vehicle,
        WebhookEvent $event,
        WebhookKind $kind,
        int $entryId,
        ?int $tripAuthor = null,
    ): void {
        $this->queue($vehicle, $event, $kind, $entryId, null, $kind === WebhookKind::Trip ? $tripAuthor ?? 0 : null);
    }

    /**
     * A reminder's status changed, or a manual reminder was made, edited or
     * deleted (#301); $change names which.
     */
    public function reminder(int $vehicleId, int $reminderId, string $change): void
    {
        if (!$this->settings->webhooksEnabled || !$this->webhooks->any()) {
            return;
        }
        $vehicle = $this->vehicles->findById($vehicleId);
        if ($vehicle !== null) {
            $this->queue($vehicle, WebhookEvent::ReminderChanged, WebhookKind::Reminder, $reminderId, $change, null);
        }
    }

    private function queue(
        Vehicle $vehicle,
        WebhookEvent $event,
        WebhookKind $kind,
        int $entryId,
        ?string $change,
        ?int $tripAuthor,
    ): void {
        if (
            !$this->settings->webhooksEnabled
            || $this->demo?->blocks(DemoRestriction::Outbound) === true
            || !$this->webhooks->any()
        ) {
            return;
        }

        $recipients = [];
        foreach ($this->candidates($vehicle) as $user) {
            if (!$this->access->can($user, VehicleAbility::View, $vehicle)) {
                continue;
            }
            if (
                $tripAuthor !== null
                && $tripAuthor !== $user->id
                && !$this->access->can($user, VehicleAbility::ViewOthersTrips, $vehicle)
            ) {
                continue;
            }
            $recipients[] = $user->id;
        }

        $now = $this->clock->now();
        $links = array_filter([
            'entry' => $event === WebhookEvent::EntryDeleted ? null : $kind->entryLink($vehicle->id, $entryId),
            'list' => $kind->listLink($vehicle->id),
            'vehicle' => '/vehicles/' . $vehicle->id,
        ], static fn (?string $link): bool => $link !== null);

        foreach ($this->webhooks->listForUsers($recipients) as $webhook) {
            if (!$webhook->receives($event)) {
                continue;
            }
            $payload = [
                'event' => $event->value,
                'id' => bin2hex(random_bytes(16)),
                'occurred_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'vehicle_id' => $vehicle->id,
                'kind' => $kind->value,
                'entry_id' => $entryId,
            ];
            if ($change !== null) {
                $payload['change'] = $change;
            }
            $payload['links'] = $links;
            $this->deliveries->insert($webhook->id, $event, $payload, $now);
        }
    }

    /**
     * The vehicle's owner and everyone it is shared with.
     *
     * @return list<User>
     */
    private function candidates(Vehicle $vehicle): array
    {
        $ids = [$vehicle->userId];
        foreach ($this->shares->listForVehicle($vehicle->id) as $share) {
            $ids[] = $share->userId;
        }
        $users = [];
        foreach (array_unique($ids) as $id) {
            $user = $this->users->find($id);
            if ($user !== null) {
                $users[] = $user;
            }
        }

        return $users;
    }
}
