<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Logbook\Domain\Attention\AttentionKind;
use Logbook\Service\Forecast\ForecastItem;
use Logbook\Service\Forecast\ForecastSource;
use Logbook\Service\Forecast\ForecastWording;
use Logbook\Support\Display\DisplayFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How a *Needs attention* item is put into words (spec.md §7.24), in the
 * current locale and units: the overview card, the dashboard widget and the
 * digest all say the same thing. Overdue work keeps each source's own
 * title (ForecastWording) and the reminders' "overdue" wording.
 */
final readonly class AttentionWording
{
    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $formatter,
        private ForecastWording $forecast,
    ) {
    }

    /**
     * "Now" or "Check": always shown as text.
     */
    public function label(AttentionItem $item): string
    {
        return $this->translator->trans('attention.severity.' . $item->severity()->value);
    }

    public function title(AttentionItem $item): string
    {
        return match ($item->kind) {
            AttentionKind::Overdue => $item->forecast === null ? '' : $this->forecast->title($item->forecast),
            AttentionKind::Reading => $this->translator->trans('attention.reading.' . ($item->warning->type ?? 'backwards'), [
                'date' => $this->formatter->instantDate($item->reading?->recordedAt),
                'odometer' => $this->formatter->distance($item->reading?->readingKm),
                'distance' => $this->formatter->distance(ltrim($item->warning->distanceKm ?? '0', '-')),
            ]),
            AttentionKind::Economy => $this->translator->trans('attention.economy.title', ['count' => $item->count]),
            AttentionKind::MileageStale => $item->latest === null
                ? $this->translator->trans('attention.mileage.none')
                : $this->translator->trans('attention.mileage.title', [
                    'date' => $this->formatter->instantDate($item->latest->recordedAt),
                ]),
            AttentionKind::TripsExceed => $this->translator->trans('attention.trips.title'),
            AttentionKind::ValuationStale => $this->translator->trans('attention.valuation.title', [
                'months' => $item->months ?? 0,
            ]),
        };
    }

    /**
     * The line under the title: how overdue, or why it matters.
     */
    public function detail(AttentionItem $item): string
    {
        return match ($item->kind) {
            AttentionKind::Overdue => $item->forecast === null ? '' : $this->overdue($item->forecast, $item->days),
            AttentionKind::Reading => $this->translator->trans(
                'attention.reading.detail.' . ($item->warning->type ?? 'backwards'),
                [
                    'previous' => $this->formatter->distance($item->warning?->previous->readingKm),
                    'date' => $this->formatter->instantDate($item->warning?->previous->recordedAt),
                ],
            ),
            AttentionKind::Economy => $this->translator->trans('attention.economy.detail'),
            AttentionKind::MileageStale => $this->translator->trans('attention.mileage.detail'),
            AttentionKind::TripsExceed => $this->translator->trans('attention.trips.detail'),
            AttentionKind::ValuationStale => $this->translator->trans('attention.valuation.detail', [
                'date' => $this->formatter->date($item->valuedOn),
            ]),
        };
    }

    /**
     * One line for a notification: "Golf GTI: 3 fill-ups look unusual".
     */
    public function line(AttentionItem $item): string
    {
        return $this->translator->trans('attention.line', [
            'vehicle' => $item->vehicle->name(),
            'title' => $this->title($item),
        ]);
    }

    /**
     * "Expired 3 days ago", "Overdue by 12 days", "Overdue at 48,000 mi".
     */
    private function overdue(ForecastItem $item, ?int $days): string
    {
        if ($days !== null && $days > 0) {
            $expires = $item->source === ForecastSource::Document || $item->source === ForecastSource::FirstInspection;

            return $this->translator->trans($expires ? 'reminders.when.expired' : 'reminders.when.overdue', ['days' => $days]);
        }
        if ($item->dueKm !== null) {
            return $this->translator->trans('attention.overdue.at', ['odometer' => $this->formatter->distance($item->dueKm)]);
        }

        return $this->translator->trans('reminders.when.overdue_now');
    }
}
