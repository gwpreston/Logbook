<?php

declare(strict_types=1);

namespace Logbook\Service\Insights;

/**
 * One observation worked out from figures Logbook already has (spec.md
 * §7.8 *Insights*): a title and a sentence as translation keys with their
 * parameters (amounts and distances already formatted, counts as numbers
 * for ICU plurals), and the page that shows the figure behind it.
 */
final readonly class Insight
{
    /**
     * @param array<string, string|int> $titleParams
     * @param array<string, string|int> $bodyParams
     * @param array<string, string|int> $routeParams
     * @param array<string, string|int> $query the link's query string
     * @param int|null $vehicleId the vehicle it is about, if one (AI insights' no-repeats filter, #358)
     * @param array<string, string|int|null> $figures the raw values behind it, canonical decimals
     *        (`computed_insights`, spec.md §7.26); empty for the kinds that show another page's figure
     */
    public function __construct(
        public InsightKind $kind,
        public InsightTone $tone,
        public string $title,
        public array $titleParams,
        public string $body,
        public array $bodyParams,
        public string $route,
        public array $routeParams = [],
        public array $query = [],
        public ?int $vehicleId = null,
        public array $figures = [],
    ) {
    }

    public function icon(): string
    {
        return $this->kind->icon();
    }
}
