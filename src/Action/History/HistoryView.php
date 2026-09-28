<?php

declare(strict_types=1);

namespace Logbook\Action\History;

use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\History\ActivityFeed;
use Logbook\Service\History\HistoryChip;
use Logbook\Service\History\HistoryPage;

/**
 * What the History tab and the fleet history share (spec.md §7.16): the
 * chosen kind chip and year from the query, the year page of the feed, and
 * its months with fill-up runs folded.
 */
final readonly class HistoryView
{
    public function __construct(
        private ActivityFeed $feed,
        private FeatureToggles $features,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles
     * @param array<array-key, mixed> $query the request's query parameters
     * @return array<string, mixed> template variables
     */
    public function context(User $user, array $vehicles, array $query): array
    {
        $enabled = $this->features->all();
        $chip = HistoryChip::fromQuery($query['kind'] ?? null, $enabled);
        $page = $this->feed->year($user, $vehicles, $chip->kinds(), self::year($query['year'] ?? null));

        return [
            'chip' => $chip,
            'chips' => HistoryChip::available($enabled),
            'page' => $page,
            'months' => HistoryPage::months($page->items, $chip->folds()),
        ];
    }

    private static function year(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^[0-9]{4}$/', $value) === 1 ? (int) $value : null;
    }
}
