<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ModificationRepository;
use Logbook\Repository\PartRepository;

/**
 * *For your insurer* (spec.md §7.39, #438): the vehicle's current (not
 * reverted) modifications, ECU tunes first, then in the category order,
 * then oldest first, each with the parts it fitted and took off, so the
 * owner can read them out or print them for a quote or a renewal. Pure
 * reads and never a cost: the page needs `View` only.
 */
final readonly class InsurerList
{
    public function __construct(
        private ModificationRepository $modifications,
        private PartRepository $parts,
    ) {
    }

    /**
     * @return list<InsurerRow>
     */
    public function forVehicle(Vehicle $vehicle): array
    {
        $current = array_values(array_filter(
            $this->modifications->listForVehicle($vehicle->id),
            static fn (Modification $m): bool => !$m->isReverted(),
        ));
        usort($current, static function (Modification $a, Modification $b): int {
            return self::rank($a) <=> self::rank($b)
                ?: $a->data->doneOn <=> $b->data->doneOn
                ?: $a->id <=> $b->id;
        });

        $byId = [];
        foreach ($this->parts->listForVehicle($vehicle->id) as $part) {
            $byId[$part->id] = $part;
        }
        $ids = array_map(static fn (Modification $m): int => $m->id, $current);
        $fittings = $this->parts->fittingsByRecords('modification', $ids);

        $rows = [];
        foreach ($current as $modification) {
            $fitted = [];
            $replaced = [];
            foreach ($fittings[$modification->id] ?? [] as $fitting) {
                $part = $byId[$fitting->partId] ?? null;
                if (!$part instanceof Part) {
                    continue;
                }
                if ($fitting->fittedModificationId === $modification->id) {
                    $fitted[$part->id] = $part;
                }
                if ($fitting->removedModificationId === $modification->id) {
                    $replaced[$part->id] = $part;
                }
            }
            $rows[] = new InsurerRow($modification, array_values($fitted), array_values($replaced));
        }

        return $rows;
    }

    public function revertedCount(Vehicle $vehicle): int
    {
        return count(array_filter(
            $this->modifications->listForVehicle($vehicle->id),
            static fn (Modification $m): bool => $m->isReverted(),
        ));
    }

    /**
     * ECU tunes first (the one insurers ask about first), then the category order.
     */
    private static function rank(Modification $modification): int
    {
        $category = $modification->data->category;
        if ($category === ModificationCategory::Tune) {
            return -1;
        }

        return (int) array_search($category, ModificationCategory::cases(), true);
    }
}
