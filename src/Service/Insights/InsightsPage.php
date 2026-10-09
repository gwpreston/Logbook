<?php

declare(strict_types=1);

namespace Logbook\Service\Insights;

use Logbook\Domain\User\User;
use Logbook\Service\Ai\Ask\AskAvailability;
use Logbook\Service\Ai\Ask\AskPage;
use Logbook\Service\Ai\Draft\DraftCards;
use Logbook\Service\Ai\Draft\DraftStore;
use Logbook\Service\Ai\Insights\AiInsightService;
use Logbook\Service\Vehicle\VehicleDataPrimer;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

/**
 * What the Insights page shows (spec.md §7.26 *Ask and the Insights page*,
 * Phase 38): the *Ask Logbook* box, *Drafts to review* (§7.28) and *Your
 * questions* when Ask is available (drafts whenever there are any), every
 * computed insight (§7.8) for the user's active vehicles, then today's AI
 * insights.
 */
final readonly class InsightsPage
{
    public function __construct(
        private VehicleService $vehicles,
        private VehicleDataPrimer $primer,
        private InsightsService $insights,
        private AskAvailability $ask,
        private AskPage $askPage,
        private DraftStore $drafts,
        private DraftCards $draftCards,
        private AiInsightService $aiInsights,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string $question what the *Ask Logbook* box starts with
     * @param string|null $error why the question in the box wasn't asked
     * @return array<string, mixed>
     */
    public function context(User $user, string $question = '', ?string $error = null): array
    {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $ask = $this->ask->isAvailable($user);
        $fleet = $this->vehicles->listFleet($user);
        // Every vehicle's records in one query per table (spec.md §8 *Page budgets*, #280).
        $this->primer->prime($fleet);

        return [
            'insights' => $this->insights->forVehicles($user, $fleet, true, $today),
            'ask_on' => $ask,
            'progress_token' => $ask ? bin2hex(random_bytes(16)) : null,
            'question' => $ask ? $question : '',
            'error' => $ask ? $error : null,
            // Drafts an MCP client left (spec.md §7.28 *Drafts to review*); their buttons need no Ask.
            'review_drafts' => array_values($this->draftCards->cards($user, $this->drafts->toReview($user))),
            // Today's AI insights from the cache; when due, the page asks for them (never on this GET).
            'ai_set' => $this->aiInsights->forToday($user),
            'ai_due' => $this->aiInsights->isDue($user),
        ] + ($ask ? $this->askPage->questions($user) : []);
    }
}
