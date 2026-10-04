<?php

declare(strict_types=1);

namespace Logbook\Support\Display;

use DateTimeZone;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DepthUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\EconomyScale;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Units\VolumeUnit;

/**
 * How numbers, units, money and dates are shown to one person. Stored per
 * user; signed-out pages use the app defaults.
 */
final readonly class DisplayPreferences
{
    public function __construct(
        public string $locale,
        public string $timezone,
        public DistanceUnit $distanceUnit,
        public VolumeUnit $volumeUnit,
        public ConsumptionUnit $consumptionUnit,
        public string $currency,
        public Theme $theme = Theme::System,
        public Accent $accent = Accent::Blue,
        public DepthUnit $depthUnit = DepthUnit::Millimetre,
    ) {
    }

    public static function defaults(string $locale, string $timezone, string $currency): self
    {
        $preset = UnitPreset::Metric;

        return new self(
            $locale,
            $timezone,
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            $currency,
            depthUnit: $preset->depth(),
        );
    }

    public function timeZone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }

    /**
     * The unit one kind of energy's economy is shown in for this person.
     */
    public function economyScale(EnergyKind $kind): EconomyScale
    {
        return EconomyScale::of($kind, $this->distanceUnit, $this->consumptionUnit, $this->volumeUnit);
    }

    public function withLocale(string $locale): self
    {
        return new self(
            $locale,
            $this->timezone,
            $this->distanceUnit,
            $this->volumeUnit,
            $this->consumptionUnit,
            $this->currency,
            $this->theme,
            $this->accent,
            $this->depthUnit,
        );
    }

    public function unitPreset(): ?UnitPreset
    {
        return UnitPreset::matching($this->distanceUnit, $this->volumeUnit, $this->consumptionUnit, $this->depthUnit);
    }
}
