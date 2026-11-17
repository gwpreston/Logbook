<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use Logbook\Domain\Vehicle\Vehicle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Names for vehicles shown side by side, so two with the same name can be
 * told apart (spec.md §7.1 *Telling same-named vehicles apart*, K203).
 *
 * A name that clashes with another in the same list (compared trimmed,
 * ignoring case) gains its registration as stored, else its year:
 * "Ford Fiesta (AB12 CDE)", "Ford Fiesta (2019)" (#630). Vehicles still
 * alike after that are numbered in the order they were added, oldest
 * first: "Ford Fiesta (1)". Only the vehicles passed in count (#631), and
 * a name with no clash is returned unchanged. `Vehicle::name()` itself
 * never changes.
 */
final readonly class VehicleLabels
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * A label per vehicle id, for the vehicles shown together.
     *
     * With `$registrationShown` the list already shows each registration
     * beside the name (a picker's "Ford Fiesta · AB12 CDE", or a plate), so
     * the registration is not added again: vehicles are compared by name and
     * registration together, and only those still alike gain their year,
     * else a number (#639).
     *
     * @param iterable<Vehicle> $vehicles
     * @return array<int, string>
     */
    public function labels(iterable $vehicles, bool $registrationShown = false): array
    {
        $labels = [];
        $groups = [];
        foreach ($vehicles as $vehicle) {
            $labels[$vehicle->id] = $vehicle->name();
            $groups[$this->seen($vehicle, $registrationShown)][] = $vehicle;
        }

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            // First the detail each one has; a vehicle with none keeps its
            // plain name, which already differs from the others' labels.
            $alike = [];
            foreach ($group as $vehicle) {
                $detail = $this->detail($vehicle, $registrationShown);
                $label = $detail === null ? $vehicle->name() : $this->wrap($vehicle->name(), $detail);
                $labels[$vehicle->id] = $label;
                $alike[self::key($label)][] = $vehicle;
            }
            // Then a number for those the detail didn't separate (the same
            // year, or neither registration nor year), oldest first.
            foreach ($alike as $same) {
                if (count($same) < 2) {
                    continue;
                }
                usort($same, static fn (Vehicle $a, Vehicle $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);
                foreach ($same as $n => $vehicle) {
                    $labels[$vehicle->id] = $this->wrap($vehicle->name(), (string) ($n + 1));
                }
            }
        }

        return $labels;
    }

    /**
     * What the reader already sees of a vehicle: its name, and its
     * registration too when the list shows it.
     */
    private function seen(Vehicle $vehicle, bool $registrationShown): string
    {
        $seen = self::key($vehicle->name());

        return $registrationShown ? $seen . "\0" . self::key($vehicle->data->registration ?? '') : $seen;
    }

    /**
     * The registration as stored (unless the list already shows it), else
     * the year, else nothing.
     */
    private function detail(Vehicle $vehicle, bool $registrationShown): ?string
    {
        $registration = trim($vehicle->data->registration ?? '');
        if (!$registrationShown && $registration !== '') {
            return $registration;
        }

        return $vehicle->data->year === null ? null : (string) $vehicle->data->year;
    }

    private function wrap(string $name, string $detail): string
    {
        return $this->translator->trans('vehicle.name_distinct', ['name' => $name, 'detail' => $detail]);
    }

    /**
     * Names are compared trimmed and ignoring case: "Ford fiesta" and
     * "Ford Fiesta" look alike.
     */
    private static function key(string $text): string
    {
        return mb_strtolower(trim($text));
    }
}
