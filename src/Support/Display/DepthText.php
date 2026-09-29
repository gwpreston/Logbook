<?php

declare(strict_types=1);

namespace Logbook\Support\Display;

use Logbook\Support\Units\DepthUnit;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A tread depth, or a range of depths, as a message part in the owner's
 * depth unit: "4.2 mm", "5.1–6.3 mm", "6/32″" (spec.md §7.17). Summaries
 * are built without a request, so the unit travels with the value; a range
 * whose ends display alike shows once.
 */
final readonly class DepthText implements TranslatableInterface
{
    /**
     * @param string $mm canonical decimal, millimetres
     * @param string|null $maxMm the deeper end of a range
     */
    public function __construct(
        private string $mm,
        private ?string $maxMm,
        private DepthUnit $unit,
        /** Formatting locale for the number ("de_CH"); the translator's locale when null. */
        private ?string $numberLocale = null,
    ) {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        $numbers = $this->numberLocale ?? $locale ?? $translator->getLocale();
        $min = $this->unit->formatNumber($this->mm, $numbers);
        $max = $this->maxMm === null ? $min : $this->unit->formatNumber($this->maxMm, $numbers);

        return $min === $max
            ? $translator->trans('units.depth.' . $this->unit->value, ['value' => $min], null, $locale)
            : $translator->trans('units.depth_range.' . $this->unit->value, ['min' => $min, 'max' => $max], null, $locale);
    }
}
