<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use DateTimeImmutable;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelChoice;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\I18n\Region;

/**
 * The fill-up form's one grouped fuel select (spec.md §7.3): what was used
 * on this vehicle lately, the families that fit it, the other fuels, then
 * the grades sold in other regions. Built here so the template only prints
 * it; the first option carrying the current value is the selected one.
 */
final class FuelPicker
{
    /**
     * @param list<FuelEntry> $entries the vehicle's fill-ups, oldest first
     * @return list<FuelPickerGroup>
     */
    public static function groups(
        Vehicle $vehicle,
        array $entries,
        string $locale,
        DateTimeImmutable $now,
        string $selected,
    ): array {
        $region = self::region($locale);
        $groups = [];

        $recent = GradeStatistics::recentlyUsed($entries, $now->modify('-12 months'));
        if ($recent !== []) {
            $groups[] = new FuelPickerGroup(
                'fuel.picker.used',
                array_map(static fn (FuelGrade $g): FuelPickerOption => self::gradeOption($g), $recent),
            );
        }

        $own = $vehicle->data->fuelType->fittingFamilies();
        foreach ($own as $family) {
            $groups[] = new FuelPickerGroup('fuel.fuel.' . $family->value, self::familyOptions($family, $region));
        }

        $others = [];
        foreach (Fuel::cases() as $family) {
            if (!in_array($family, $own, true)) {
                $others = [...$others, ...self::familyOptions($family, $region)];
            }
        }
        $groups[] = new FuelPickerGroup('fuel.picker.other_fuels', $others);

        $more = array_values(array_filter(FuelGrade::cases(), static fn (FuelGrade $g): bool => !$g->isMainFor($region)));
        if ($more !== []) {
            $groups[] = new FuelPickerGroup(
                'fuel.picker.more_grades',
                array_map(static fn (FuelGrade $g): FuelPickerOption => self::gradeOption($g), $more),
            );
        }

        return self::select($groups, $selected);
    }

    /**
     * The vehicle form's *Default grade* choices: the grades of each family
     * a vehicle can have a default in, this region's first.
     *
     * @return array<string, list<FuelGrade>> keyed by Fuel value
     */
    public static function defaultGradeGroups(string $locale): array
    {
        $region = self::region($locale);
        $groups = [];
        foreach ([Fuel::Petrol, Fuel::Diesel, Fuel::Electricity] as $family) {
            $grades = FuelGrade::forFamily($family);
            $groups[$family->value] = [
                ...array_filter($grades, static fn (FuelGrade $g): bool => $g->isMainFor($region)),
                ...array_filter($grades, static fn (FuelGrade $g): bool => !$g->isMainFor($region)),
            ];
        }

        return $groups;
    }

    /**
     * The region of a locale ("en_US" → "US"), or null when it names none.
     */
    public static function region(string $locale): ?string
    {
        return Region::of($locale);
    }

    /**
     * "Family — grade not recorded", then the family's grades for this region.
     *
     * @return list<FuelPickerOption>
     */
    private static function familyOptions(Fuel $family, ?string $region): array
    {
        $grades = FuelGrade::forFamily($family);
        $options = [new FuelPickerOption(
            new FuelChoice($family),
            $grades === [] ? 'fuel.fuel.' . $family->value : 'fuel.picker.not_recorded.' . $family->value,
        )];
        foreach ($grades as $grade) {
            if ($grade->isMainFor($region)) {
                $options[] = self::gradeOption($grade);
            }
        }

        return $options;
    }

    private static function gradeOption(FuelGrade $grade): FuelPickerOption
    {
        return new FuelPickerOption(new FuelChoice($grade->family(), $grade), $grade->labelKey());
    }

    /**
     * @param list<FuelPickerGroup> $groups
     * @return list<FuelPickerGroup>
     */
    private static function select(array $groups, string $selected): array
    {
        $found = false;
        $result = [];
        foreach ($groups as $group) {
            $options = [];
            foreach ($group->options as $option) {
                $isSelected = !$found && $option->value() === $selected;
                $found = $found || $isSelected;
                $options[] = $isSelected ? new FuelPickerOption($option->choice, $option->labelKey, true) : $option;
            }
            $result[] = new FuelPickerGroup($group->labelKey, $options);
        }

        return $result;
    }
}
