<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

/**
 * One option of the grouped fuel picker (spec.md §7.3): a family, with or
 * without a grade. Its form value is `family` or `family:grade`
 * (`petrol:e10_95`), so the choice survives a plain form post.
 */
final readonly class FuelChoice
{
    public function __construct(
        public Fuel $fuel,
        public ?FuelGrade $grade = null,
    ) {
    }

    /**
     * The choice a picker value names, or null for anything that is not one
     * (unknown family or grade, a grade of another family, extra parts).
     */
    public static function fromValue(string $value): ?self
    {
        $parts = explode(':', trim($value));
        if (count($parts) > 2) {
            return null;
        }
        $fuel = Fuel::tryFrom($parts[0]);
        if ($fuel === null) {
            return null;
        }
        if (!isset($parts[1])) {
            return new self($fuel);
        }
        $grade = FuelGrade::tryFrom($parts[1]);

        return $grade === null || $grade->family() !== $fuel ? null : new self($fuel, $grade);
    }

    public function value(): string
    {
        return $this->grade === null ? $this->fuel->value : $this->fuel->value . ':' . $this->grade->value;
    }
}
