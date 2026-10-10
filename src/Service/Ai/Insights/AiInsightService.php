<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Insights;

use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Ask\ToolRun;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\Insights\AiInsight;
use Logbook\Domain\Ai\Insights\AiInsightSet;
use Logbook\Domain\Ai\Insights\AiInsightTopic;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AiInsightRepository;
use Logbook\Service\Ai\AiFailure;
use Logbook\Service\Ai\AiGateway;
use Logbook\Service\Ai\AiSession;
use Logbook\Service\Ai\Ask\AskAvailability;
use Logbook\Service\Ai\Ask\Conversation;
use Logbook\Service\Ai\Ask\GroundingCheck;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Ai\Provider\ChatMessage;
use Logbook\Service\Ai\Provider\ChatRequest;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Insights\EconomyUp;
use Logbook\Service\Insights\FuelSaving;
use Logbook\Service\Insights\FuelSavingFigure;
use Logbook\Service\Insights\Insight;
use Logbook\Service\Insights\InsightsService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\UserDisplayScope;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AI insights (spec.md §7.26 *AI insights*, Phase 33.4): up to four
 * observations the model finds with the *Ask* read tools, as the user,
 * once a day and cached for it. Only while Ask is available to the user;
 * every number goes through Ask's grounding check. Reading the cache never
 * calls a model; only generate() does, under the user's AI lock.
 *
 * Phase 42: the model is told the computed insights (§7.8) and tags each
 * of its own with a topic and vehicles (#358). Reading drops an insight
 * with a figure no tool returned (#354), and a `fuel_cost` or `economy`
 * one for a vehicle showing *Fuel saving* or *Economy up*: the instruction
 * saves a slot, the filter is the guarantee.
 */
final readonly class AiInsightService
{
    public const int MAX_INSIGHTS = 4;
    private const int MAX_TITLE = 120;
    private const int MAX_BODY = 400;
    /** The answer's shape, after the translated rules (braces would be placeholders in a message). */
    private const string SHAPE = '{"insights": [{"title": "…", "body": "…", "sources": ["tool_name"], '
        . '"topic": "fuel_cost|economy|other", "vehicles": [1]}]}';

    public function __construct(
        private AskAvailability $availability,
        private AiGateway $gateway,
        private ToolRegistry $tools,
        private ToolKit $kit,
        private GroundingCheck $grounding,
        private AiInsightRepository $repository,
        private UserDisplayScope $display,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private VehicleService $vehicles,
        private InsightsService $computed,
        private FuelSaving $fuelSaving,
        private EconomyUp $economyUp,
    ) {
    }

    public function isAvailable(User $user): bool
    {
        return $this->availability->isAvailable($user);
    }

    /**
     * The user's local date, as the sets are keyed.
     */
    public function today(User $user): string
    {
        return $this->kit->today($user)->format('Y-m-d');
    }

    /**
     * Today's set, as far as the user may still see it; null with AI off
     * for them or nothing made today.
     */
    public function forToday(User $user): ?AiInsightSet
    {
        if (!$this->isAvailable($user)) {
            return null;
        }
        $set = $this->repository->find($user->id);
        if ($set === null || !$set->isFor($this->today($user))) {
            return null;
        }

        return $this->shown($user, $set);
    }

    /**
     * The kept set when it was made for the user's today or yesterday, as
     * far as they may still see it (spec.md §7.11 *The monthly briefing*,
     * #362): the monthly digest runs early on the 1st, often before the
     * day's set. Never calls a model; null with AI off for them.
     */
    public function recent(User $user): ?AiInsightSet
    {
        if (!$this->isAvailable($user)) {
            return null;
        }
        $set = $this->repository->find($user->id);
        $today = $this->kit->today($user);
        $fresh = $set !== null
            && ($set->isFor($today->format('Y-m-d')) || $set->isFor($today->modify('-1 day')->format('Y-m-d')));
        if (!$fresh) {
            return null;
        }

        return $this->shown($user, $set);
    }

    /**
     * Whether today's set is still to be made (the page then asks for it).
     */
    public function isDue(User $user): bool
    {
        if (!$this->isAvailable($user)) {
            return false;
        }
        $set = $this->repository->find($user->id);

        return $set === null || !$set->isFor($this->today($user));
    }

    /**
     * Make today's set and keep it, replacing any earlier one. A failure
     * once the model was reached is kept as the day's set (shown with
     * *Refresh*); one before it (AI off, busy) is thrown and nothing kept.
     *
     * @throws AiFailure
     */
    public function generate(User $user): AiInsightSet
    {
        if (!$this->isAvailable($user)) {
            throw new AiFailure(ErrorCode::Disabled);
        }
        $set = $this->display->run($user, fn (): AiInsightSet => $this->gateway->session(
            $user,
            AiTaskName::Ask,
            Conversation::DEADLINE_SECONDS,
            fn (AiSession $session): AiInsightSet => $this->find($user, $session),
        ));
        $this->repository->save($user->id, $set);

        return $this->shown($user, $set);
    }

    /**
     * What may be shown of a set: insights about vehicles the user can
     * still see, every figure matched (#354), and no repeat of a computed
     * insight (#358). The computed ones are worked out only when an
     * insight's topic could repeat one.
     */
    private function shown(User $user, AiInsightSet $set): AiInsightSet
    {
        $set = $set->visibleTo(array_map(static fn (Vehicle $v): int => $v->id, $this->kit->fleet($user)))
            ->kept(static fn (AiInsight $insight): bool => $insight->isGrounded());
        $topics = array_map(static fn (AiInsight $i): AiInsightTopic => $i->topic, $set->insights);
        if (!in_array(AiInsightTopic::FuelCost, $topics, true) && !in_array(AiInsightTopic::Economy, $topics, true)) {
            return $set;
        }
        $fleet = $this->vehicles->listFleet($user);
        $showing = [
            AiInsightTopic::FuelCost->value => in_array(AiInsightTopic::FuelCost, $topics, true)
                ? array_map(
                    static fn (FuelSavingFigure $f): int => $f->vehicle->id,
                    $this->fuelSaving->forVehicles($user, $fleet),
                )
                : [],
            AiInsightTopic::Economy->value => in_array(AiInsightTopic::Economy, $topics, true)
                ? array_map(static fn (Insight $i): ?int => $i->vehicleId, $this->economyUp->forVehicles($user, $fleet))
                : [],
        ];

        return $set->kept(static fn (AiInsight $insight): bool
            => array_intersect($insight->vehicles, $showing[$insight->topic->value] ?? []) === []);
    }

    private function find(User $user, AiSession $session): AiInsightSet
    {
        $context = $this->context($user);
        $system = $this->translator->trans('ai_insights.system.text', ['max' => self::MAX_INSIGHTS]) . "\n" . self::SHAPE . "\n\n"
            . $this->translator->trans('ask.system.context') . "\n" . $context . "\n\n"
            . $this->translator->trans('ai_insights.system.computed') . "\n" . $this->computedList($user);
        $definitions = $this->tools->readDefinitions($user);
        $offered = array_map(static fn (ToolDefinition $d): string => $d->name, $definitions);
        $messages = [ChatMessage::user($this->translator->trans('ai_insights.system.request', ['max' => self::MAX_INSIGHTS]))];

        $runs = [];
        $text = null;
        $error = null;
        $started = hrtime(true);
        for ($round = 0; $round < Conversation::MAX_ROUNDS; $round++) {
            if ((hrtime(true) - $started) / 1e9 > Conversation::DEADLINE_SECONDS) {
                $error = ErrorCode::Timeout;
                break;
            }
            try {
                $result = $session->chat(new ChatRequest($messages, $system, $definitions));
            } catch (AiFailure $failure) {
                $error = $failure->error;
                break;
            }
            if ($result->toolCalls === []) {
                $text = trim($result->text);
                break;
            }
            $messages[] = $result->toMessage();
            foreach ($result->toolCalls as $call) {
                if (count($runs) >= Conversation::MAX_TOOL_CALLS) {
                    $content = json_encode(['error' => Conversation::ENOUGH], JSON_THROW_ON_ERROR);
                } elseif (!in_array($call->name, $offered, true)) {
                    // Only the read tools are offered: a draft tool is never run here.
                    $missing = sprintf('There is no tool called "%s".', $call->name);
                    $content = json_encode(['error' => $missing], JSON_THROW_ON_ERROR);
                } else {
                    $run = $this->tools->run($user, $call);
                    $runs[] = $run;
                    $content = $run->content();
                }
                $messages[] = ChatMessage::toolResult($call, $content);
            }
        }

        $insights = $error === null && $text !== null && $text !== ''
            ? $this->parse($text, $runs, [$context, ...array_map(static fn (ToolRun $r): string => $r->content(), $runs)], $user)
            : null;
        if ($insights === null && $error === null) {
            $error = ErrorCode::BadResponse;
        }

        return new AiInsightSet(
            day: $this->today($user),
            insights: $insights ?? [],
            runs: $insights === null ? [] : $runs,
            connectionName: $session->connection->name,
            location: $session->connection->location,
            model: $session->model,
            error: $error,
            createdAt: $this->clock->now(),
        );
    }

    /**
     * The model's JSON, read defensively: `{"insights": [{"title", "body",
     * "sources": [tool names]}]}`, possibly in a code fence. Null when it
     * is not that shape at all, so nothing half-read is ever shown.
     *
     * @param list<ToolRun> $runs
     * @param list<string> $sources what the grounding check matches against
     * @return list<AiInsight>|null
     */
    private function parse(string $text, array $runs, array $sources, User $user): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!is_array($decoded) || !is_array($decoded['insights'] ?? null)) {
            return null;
        }

        $fleet = array_map(static fn (Vehicle $v): int => $v->id, $this->kit->fleet($user));
        $insights = [];
        foreach ($decoded['insights'] as $item) {
            if (count($insights) >= self::MAX_INSIGHTS) {
                break;
            }
            $title = is_array($item) && is_string($item['title'] ?? null) ? trim($item['title']) : '';
            $body = is_array($item) && is_string($item['body'] ?? null) ? trim($item['body']) : '';
            if ($title === '' || $body === '') {
                continue;
            }
            $names = is_array($item['sources'] ?? null) ? array_filter($item['sources'], is_string(...)) : [];
            $cited = [];
            foreach ($runs as $i => $run) {
                if ($run->result !== null && in_array($run->name, $names, true)) {
                    $cited[] = $i;
                }
            }
            if ($cited === []) {
                // Every insight comes from a tool result (§7.26): without one it is left out.
                continue;
            }
            $title = mb_substr($title, 0, self::MAX_TITLE);
            $body = mb_substr($body, 0, self::MAX_BODY);
            // Only the user's own vehicles, whatever ids the model sent.
            $named = is_array($item['vehicles'] ?? null) ? $item['vehicles'] : [];
            $vehicles = array_values(array_intersect(
                array_map(intval(...), array_filter($named, is_numeric(...))),
                $fleet,
            ));
            $insights[] = new AiInsight(
                $title,
                $body,
                $cited,
                $this->grounding->ungrounded($title . "\n" . $body, $sources, $user->preferences->locale),
                AiInsightTopic::read($item['topic'] ?? null),
                $vehicles,
            );
        }

        return $insights;
    }

    /**
     * The computed insights the user would see (§7.8, all of them), as the
     * model is told them so it doesn't repeat one: kind, vehicle and title.
     */
    private function computedList(User $user): string
    {
        $rows = array_map(fn (Insight $insight): array => [
            'kind' => $insight->kind->value,
            'vehicle' => $insight->vehicleId,
            'title' => $this->translator->trans($insight->title, $insight->titleParams),
        ], $this->computed->forVehicles($user, $this->vehicles->listFleet($user), true, $this->kit->today($user)));

        return json_encode(
            $rows,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * What the model knows before any tool, as for a question (§7.26
     * *Context sent to the model*).
     */
    private function context(User $user): string
    {
        $preferences = $user->preferences;

        return json_encode([
            'today' => $this->today($user),
            'time_zone' => $preferences->timezone,
            'locale' => $preferences->locale,
            'distance_unit' => $preferences->distanceUnit->value,
            'volume_unit' => $preferences->volumeUnit->value,
            'consumption_unit' => $preferences->consumptionUnit->value,
            'currency' => $preferences->currency,
            'vehicles' => array_map($this->kit->vehicleRow(...), $this->kit->fleet($user)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
