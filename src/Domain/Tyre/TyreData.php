<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

/**
 * What a tyre is, as entered and validated (spec.md §6 Tyre). Where it is
 * (status, position, set) is not here: that only changes through a tyre
 * change.
 */
final readonly class TyreData
{
    public function __construct(
        public ?string $brand = null,
        public ?string $model = null,
        /** Normalised: upper case, whitespace collapsed ("205/55 R16 91V"). */
        public ?string $size = null,
        public ?TyreSeason $season = null,
        public ?DotCode $dot = null,
        public ?string $notes = null,
    ) {
    }

    /**
     * "Michelin Primacy 4", or '' when neither is known.
     */
    public function name(): string
    {
        return trim(($this->brand ?? '') . ' ' . ($this->model ?? ''));
    }

    public static function normaliseSize(string $size): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $size)));
    }
}
