<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

/**
 * A provider's licence: its name, the attribution shown wherever its data
 * appears (a translation key), and a link to the licence (spec.md §7.34
 * *Attribution*).
 */
final readonly class ProviderLicence
{
    public function __construct(
        public string $name,
        public string $attributionKey,
        public string $url,
    ) {
    }
}
