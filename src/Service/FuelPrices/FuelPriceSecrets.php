<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Repository\FuelPriceSecretRepository;
use Logbook\Service\Ai\SecretBox;
use Logbook\Service\Ai\SecretUnreadable;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * A provider's credentials (spec.md §7.34 *Credentials*): stored sealed or
 * as `env:NAME` exactly as an AI connection's secret (§7.25), opened only
 * for a run, never shown back.
 */
final readonly class FuelPriceSecrets
{
    public function __construct(
        private FuelPriceSecretRepository $repository,
        private SecretBox $box,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, bool> by slot: whether a value is stored
     */
    public function states(PriceProvider $provider): array
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
    public function variable(PriceProvider $provider, string $slot): ?string
    {
        $stored = $this->repository->forProvider($provider->code())[$slot] ?? null;

        return $stored === null ? null : SecretBox::variable($stored);
    }

    public function complete(PriceProvider $provider): bool
    {
        return !in_array(false, $this->states($provider), true);
    }

    public function canStore(string $value): bool
    {
        return $this->box->canStore($value);
    }

    public function store(PriceProvider $provider, string $slot, #[SensitiveParameter] string $value): void
    {
        $this->repository->put($provider->code(), $slot, $this->box->store($value), $this->clock->now());
    }

    /**
     * @throws SecretUnreadable when one can't be opened (another
     *   SESSION_SECRET, or an unset variable)
     */
    public function open(PriceProvider $provider): ProviderCredentials
    {
        $stored = $this->repository->forProvider($provider->code());
        $values = [];
        foreach (array_keys($provider->credentials()) as $slot) {
            $values[$slot] = isset($stored[$slot]) ? $this->box->open($slot, $stored[$slot]) : '';
        }

        return new ProviderCredentials($values);
    }
}
