<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use Logbook\Service\FuelPrices\ProviderCredentials;
use Logbook\Service\FuelPrices\ProviderLicence;

/**
 * A source of official inspection records (spec.md §7.38 *Provider*). One
 * ships, DVSA (UK); another country is one adapter registered in
 * MotHistoryRegistry.
 */
interface MotHistoryProvider
{
    /** Stored in settings: never change it once released. */
    public function code(): string;

    /** Translation keys: its name, its description, and what it sends. */
    public function nameKey(): string;

    public function descriptionKey(): string;

    public function sendsKey(): string;

    public function licence(): ProviderLicence;

    /**
     * The credentials it needs, by slot, each with its label's translation
     * key.
     *
     * @return array<string, string>
     */
    public function credentials(): array;

    /**
     * Whether a typed credential is acceptable for its slot (the token URL
     * must be the provider's own sign-in host).
     */
    public function acceptsCredential(string $slot, string $value): bool;

    /**
     * ISO 3166 alpha-2 codes of the countries it covers.
     *
     * @return list<string>
     */
    public function countries(): array;

    /**
     * Signs in once for a fetch or a job run. Nothing about a vehicle is
     * sent.
     *
     * @throws MotHistoryFailure
     */
    public function connect(ProviderCredentials $credentials): MotHistoryClient;
}
