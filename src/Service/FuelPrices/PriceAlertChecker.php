<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use DateTimeImmutable;
use Logbook\Repository\PriceAlertRepository;
use Logbook\Repository\ProviderStationRepository;
use Logbook\Repository\StationRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Notification\NotificationComposer;
use Logbook\Service\Notification\NotificationDispatcher;
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
            if (!$alert->isArmed() || !$this->alerts->claim($alert->id, $now)) {
                continue;
            }
            $user = $this->users->find($alert->userId);
            $station = $this->stations->find($alert->stationId);
            if ($user === null || !$user->isActive() || $station === null) {
                continue;
            }
            try {
                $preferences = $this->preferences->notificationPreferences($user->id);
                $report = $this->dispatcher->dispatch(
                    $this->composer->priceAlert($user, $station, $alert, $listed, $provider->currency()),
                    Recipient::of($user, $preferences),
                    $preferences,
                );
                $sent = $report->anyDelivered() || $report->hadNoChannels();
            } catch (Throwable $e) {
                $this->logger->error('Price alert {id} failed: {message}', ['id' => $alert->id, 'message' => $e->getMessage()]);
                $sent = false;
            }
            if ($sent) {
                $counts['sent']++;
            } else {
                // Every channel failed: the next sync tries again.
                $this->alerts->rearm($alert->id, $now);
            }
        }

        return $counts;
    }
}
