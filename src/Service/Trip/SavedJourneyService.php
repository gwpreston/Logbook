<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use Logbook\Domain\Trip\SavedJourney;
use Logbook\Domain\Trip\SavedJourneyData;
use Logbook\Domain\User\User;
use Logbook\Repository\SavedJourneyRepository;
use LogicException;
use Psr\Clock\ClockInterface;

/**
 * A user's saved journeys (spec.md §7.22): created from the trip form's
 * *Save as a journey* or Settings → Trips, renamed, reordered, deleted.
 */
final readonly class SavedJourneyService
{
    public function __construct(
        private SavedJourneyRepository $journeys,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<SavedJourney> in the user's order
     */
    public function forUser(User $user): array
    {
        return $this->journeys->listForUser($user->id);
    }

    public function find(User $user, int $id): ?SavedJourney
    {
        return $this->journeys->find($user->id, $id);
    }

    public function create(User $user, SavedJourneyData $data): SavedJourney
    {
        $id = $this->journeys->insert($user->id, $data, $this->clock->now());

        return $this->journeys->find($user->id, $id) ?? throw new LogicException('Journey not saved.');
    }

    public function update(User $user, SavedJourney $journey, SavedJourneyData $data): void
    {
        $this->journeys->update($user->id, $journey->id, $data, $this->clock->now());
    }

    /**
     * Move a journey one place up or down.
     */
    public function move(User $user, SavedJourney $journey, int $by): void
    {
        $ids = array_map(static fn (SavedJourney $j): int => $j->id, $this->forUser($user));
        $at = array_search($journey->id, $ids, true);
        $to = $at === false ? false : $at + $by;
        if ($at === false || $to < 0 || $to >= count($ids)) {
            return;
        }
        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $this->journeys->reorder($user->id, array_values($ids), $this->clock->now());
    }

    /**
     * Trips logged from it keep everything they copied.
     */
    public function delete(User $user, SavedJourney $journey): void
    {
        $this->journeys->delete($user->id, $journey->id);
    }
}
