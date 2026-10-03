<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\ProviderKind;

/**
 * A source of listed fuel prices (spec.md §7.34 *Providers*). A bulk
 * provider also implements BulkPriceProvider, an area provider
 * AreaPriceProvider. Adding a country is one adapter registered in
 * ProviderRegistry (docs/stations.md *Adding a provider*).
 */
interface PriceProvider
{
    /** Stored in settings and on links: never change it once released. */
    public function code(): string;

    public function kind(): ProviderKind;

    /** Translation keys: its name, its description, and what it sends. */
    public function nameKey(): string;

    public function descriptionKey(): string;

    public function sendsKey(): string;

    public function licence(): ProviderLicence;

    /**
     * The credentials it needs, by slot (`client_id`, `client_secret`), each
     * with its label's translation key; none for an open feed.
     *
     * @return array<string, string>
     */
    public function credentials(): array;

    /** The host it calls, for the Internet acknowledgement of an area provider. */
    public function host(): string;

    /** Never refreshed more often than this. */
    public function minimumRefreshMinutes(): int;

    /** ISO 4217: the currency its prices are in. */
    public function currency(): string;

    /**
     * Grades an admin may choose for one of its codes (UK Fuel Finder's
     * E5), the first being the default; empty when nothing is a choice.
     *
     * @return array<string, list<FuelGrade>>
     */
    public function gradeChoices(): array;

    /**
     * Its codes mapped to Logbook's, with the admin's choices applied.
     *
     * @param array<string, FuelGrade> $chosen
     * @return array<string, FuelGrade>
     */
    public function gradeMap(array $chosen = []): array;
}
