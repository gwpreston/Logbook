<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use Logbook\Repository\MotHistorySecretRepository;
use Logbook\Service\Ai\SecretBox;
use Logbook\Service\Ai\SecretUnreadable;
use Logbook\Service\FuelPrices\ProviderCredentials;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * The MOT history provider's credentials (spec.md §7.38 *Settings*): sealed
 * or `env:NAME` as an AI connection's secret (§7.25), opened only for a
 * fetch, *Test* or a job run, never shown back.
 */
final readonly class MotHistorySecrets
{
    public function __construct(
        private MotHistorySecretRepository $repository,
        private SecretBox $box,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, bool> by slot: whether a value is stored
     */
    public function states(MotHistoryProvider $provider): array
    {
        $stored = $this->repository->forProvider($provider->code());
        $states = [];
        foreach (array_keys($provider->credentials()) as $slot) {
            $states[$slot] = ($stored[$slot] ?? '') !== '';
        }

        return $states;
    }

    /**
     * The `env:` variable a slot reads, if it is one.
     */
    public function variable(MotHistoryProvider $provider, string $slot): ?string
    {
        $stored = $this->repository->forProvider($provider->code())[$slot] ?? null;

        return $stored === null ? null : SecretBox::variable($stored);
    }

    public function complete(MotHistoryProvider $provider): bool
    {
        return !in_array(false, $this->states($provider), true);
    }

    public function canStore(string $value): bool
    {
        return $this->box->canStore($value);
    }

    public function store(MotHistoryProvider $provider, string $slot, #[SensitiveParameter] string $value): void
    {
        $this->repository->put($provider->code(), $slot, $this->box->store($value), $this->clock->now());
    }

    /**
     * @throws SecretUnreadable when one can't be opened (another
     *   SESSION_SECRET, or an unset variable)
     */
    public function open(MotHistoryProvider $provider): ProviderCredentials
    {
        $stored = $this->repository->forProvider($provider->code());
        $values = [];
        foreach (array_keys($provider->credentials()) as $slot) {
            $values[$slot] = isset($stored[$slot]) ? $this->box->open($slot, $stored[$slot]) : '';
        }

        return new ProviderCredentials($values);
    }
}
