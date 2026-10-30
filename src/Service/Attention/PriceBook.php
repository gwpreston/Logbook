<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Closure;
use Logbook\Domain\Fuel\FuelEntry;

/**
 * The owners' fill-ups by currency, loaded and grouped at most once for one *Needs
 * attention* list however many of its vehicles need them (the price
 * check's wider comparison, spec.md §7.24 item 8). One per list.
 */
final class PriceBook
{
    /** @var array<string, PriceGroups> by "owner|currency" */
    private array $loaded = [];

    /**
     * Loaded, sorted and grouped once (Phase 54.5: they were sorted again
     * for each vehicle).
     *
     * @param Closure(): list<FuelEntry> $load
     */
    public function fillUps(int $ownerId, string $currency, Closure $load): PriceGroups
    {
        return $this->loaded[$ownerId . '|' . $currency] ??= PriceGroups::of($load());
    }
}
