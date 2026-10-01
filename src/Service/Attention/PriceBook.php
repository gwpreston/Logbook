<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Closure;
use Logbook\Domain\Fuel\FuelEntry;

/**
 * The owners' fill-ups by currency, loaded at most once for one *Needs
 * attention* list however many of its vehicles need them (the price
 * check's wider comparison, spec.md §7.24 item 8). One per list.
 */
final class PriceBook
{
    /** @var array<string, list<FuelEntry>> by "owner|currency" */
    private array $loaded = [];

    /**
     * @param Closure(): list<FuelEntry> $load
     * @return list<FuelEntry>
     */
    public function fillUps(int $ownerId, string $currency, Closure $load): array
    {
        return $this->loaded[$ownerId . '|' . $currency] ??= $load();
    }
}
