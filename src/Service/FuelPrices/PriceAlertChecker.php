<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\User\User;
use Logbook\Repository\PriceAlertRepository;
use Logbook\Repository\ProviderStationRepository;
use Logbook\Repository\StationRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Notification\NotificationComposer;
use Logbook\Service\Notification\NotificationDispatcher;
use Logbook\Service\Notification\NotificationPreferences;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Number\Decimal;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Price alerts after a sync (spec.md §7.34 *Price alerts*, decided
 * 2026-10-03, #138): an armed alert whose station's fresh listed price is
 * below its price is claimed (armed → triggered) and then sent, once; a
 * delivery that fails on every channel re-arms it. A triggered alert whose
 * price is back at or above its price is re-armed. Closed stations and
 * prices older than 48 hours change nothing.
 *
 * Phase 36.4 (spec.md §7.11): a user inside their quiet hours is skipped
 * and their alerts stay armed, so the first check after sends them if the
 * price is still below; everything of one user's that fires in one check
 * goes as one message (#253).
 */
final readonly class PriceAlertChecker
{
    public function __construct(
        private PriceAlertRepository $alerts,
        private ProviderStationRepository $providerStations,
        private StationRepository $stations,
        private UserRepository $users,
        private ReminderSettingsStore $preferences,
        private NotificationComposer $composer,
        private NotificationDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{sent: int, rearmed: int}
     */
    public function check(PriceProvider $provider, DateTimeImmutable $now): array
    {
        $counts = ['sent' => 0, 'rearmed' => 0];
        $found = $this->alerts->forProvider($provider->code());
        if ($found === []) {
            return $counts;
        }
        $sources = $this->providerStations->findByRefs(
            $provider->code(),
            array_map(static fn (array $a): string => $a['ref'], $found),
        );
        $prices = $this->providerStations->prices(array_values(array_map(static fn ($s): int => $s->id, $sources)));

        $recipients = [];
        $firing = [];
        foreach ($found as ['alert' => $alert, 'ref' => $ref]) {
            $source = $sources[$ref] ?? null;
            $listed = $source === null ? null : ($prices[$source->id][$alert->grade->value] ?? null);
            if ($source === null || !$source->isOpen() || $listed === null || !$listed->isFresh($now)) {
                continue;
            }
            $below = Decimal::compare($listed->price, $alert->below) < 0;
            if (!$below) {
                if (!$alert->isArmed()) {
                    $this->alerts->rearm($alert->id, $now);
                    $counts['rearmed']++;
                }
                continue;
            }
            if (!$alert->isArmed()) {
                continue;
            }
            if (!array_key_exists($alert->userId, $recipients)) {
                $recipients[$alert->userId] = $this->recipient($alert->userId, $now);
            }
            // Not claimed in quiet hours: it stays armed for the first check after.
            if ($recipients[$alert->userId] === null) {
                continue;
            }
            $station = $this->stations->find($alert->stationId);
            if ($station === null || !$this->alerts->claim($alert->id, $now)) {
                continue;
            }
            $firing[$alert->userId][] = [$station, $alert, $listed];
        }

        foreach ($firing as $userId => $alerts) {
            $recipient = $recipients[$userId] ?? null;
            if ($recipient === null) {
                continue;
            }
            [$user, $preferences] = $recipient;
            try {
                $report = $this->dispatcher->dispatch(
                    $this->composer->priceAlerts($user, $alerts, $provider->currency()),
                    Recipient::of($user),
                    $preferences,
                );
                $sent = $report->anyDelivered() || $report->hadNoChannels();
            } catch (Throwable $e) {
                $this->logger->error('Price alerts for user {user} failed: {message}', [
                    'user' => $userId,
                    'message' => $e->getMessage(),
                ]);
                $sent = false;
            }
            foreach ($alerts as [, $alert]) {
                if ($sent) {
                    $counts['sent']++;
                } else {
                    // Every channel failed: the next sync tries again.
                    $this->alerts->rearm($alert->id, $now);
                }
            }
        }

        return $counts;
    }

    /**
     * An active user and their preferences, or null when their alerts can't
     * be sent now: gone, inactive, or inside their quiet hours.
     *
     * @return array{User, NotificationPreferences}|null
     */
    private function recipient(int $userId, DateTimeImmutable $now): ?array
    {
        $user = $this->users->find($userId);
        if ($user === null || !$user->isActive()) {
            return null;
        }
        $preferences = $this->preferences->notificationPreferences($user->id);
        if ($preferences->quiet?->contains($now, $user->preferences->timeZone()) === true) {
            return null;
        }

        return [$user, $preferences];
    }
}
