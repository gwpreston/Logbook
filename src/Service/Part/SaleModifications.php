<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartOrigin;
use Logbook\Domain\Part\PartStatus;

/**
 * The sale pack's *Modifications*, *Returned to standard* and *Kept with
 * the car* sections (spec.md §7.19, §7.39, #439): what a buyer should be
 * told. Names, brands and part numbers only: never a cost, a price or what
 * a part sold for.
 */
final readonly class SaleModifications
{
    /**
     * @param list<InsurerRow> $current current modifications with the parts they fitted
     * @param list<array{modification: Modification, restored: list<Part>}> $returned reverted, original parts back on
     * @param list<Part> $kept the vehicle's stored parts
     */
    public function __construct(
        public array $current,
        public array $returned,
        public array $kept,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->current === [] && $this->returned === [] && $this->kept === [];
    }

    public static function origin(Part $part): string
    {
        return $part->data->origin === PartOrigin::Original ? 'original' : 'spare';
    }

    /**
     * @param list<Modification> $modifications every modification of the vehicle
     * @param list<InsurerRow> $current
     * @param list<Part> $parts every part of the vehicle
     * @param array<int, list<\Logbook\Domain\Part\PartFitting>> $removedBy fittings each modification ended, by modification id
     */
    public static function of(array $modifications, array $current, array $parts, array $removedBy): self
    {
        $byId = [];
        foreach ($parts as $part) {
            $byId[$part->id] = $part;
        }
        $returned = [];
        foreach ($modifications as $modification) {
            if (!$modification->isReverted()) {
                continue;
            }
            $restored = [];
            foreach ($removedBy[$modification->id] ?? [] as $fitting) {
                $part = $byId[$fitting->partId] ?? null;
                if (
                    $fitting->removedModificationId === $modification->id
                    && $part !== null
                    && $part->data->origin === PartOrigin::Original
                    && $part->status === PartStatus::Fitted
                ) {
                    $restored[$part->id] = $part;
                }
            }
            if ($restored !== []) {
                $returned[] = ['modification' => $modification, 'restored' => array_values($restored)];
            }
        }
        $kept = array_values(array_filter($parts, static fn (Part $p): bool => $p->status === PartStatus::Stored));

        return new self($current, $returned, $kept);
    }
}
