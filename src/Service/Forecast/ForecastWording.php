<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How a *Coming up* item is named (spec.md §7.18), for the pages and CSV
 * alike: a renewal is "Renew {title, else type}", everything else its own
 * title, and the first MOT "First MOT".
 */
final readonly class ForecastWording
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function title(ForecastItem $item): string
    {
        if ($item->source === ForecastSource::Document) {
            $what = $item->title ?? $this->translator->trans('compliance.type.' . ($item->category ?? 'other'));

            return $this->translator->trans('coming_up.renew', ['what' => $what]);
        }

        if ($item->source === ForecastSource::FirstInspection) {
            return $this->translator->trans('compliance.first_inspection.title');
        }

        return $item->title !== null && $item->title !== ''
            ? $item->title
            : $this->translator->trans('reminders.untitled');
    }

    public function source(ForecastSource $source): string
    {
        return $this->translator->trans('coming_up.source.' . $source->value);
    }
}
