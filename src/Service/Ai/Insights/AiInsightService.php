<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Insights;

use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Ask\ToolRun;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\Insights\AiInsight;
use Logbook\Domain\Ai\Insights\AiInsightSet;
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
use Logbook\Support\Display\UserDisplayScope;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AI insights (spec.md §7.26 *AI insights*, Phase 33.4): up to four
 * observations the model finds with the *Ask* read tools, as the user,
 * once a day and cached for it. Only while Ask is available to the user;
 * every number goes through Ask's grounding check. Reading the cache never
 * calls a model; only generate() does, under the user's AI lock.
 */
final readonly class AiInsightService
{
    public const int MAX_INSIGHTS = 4;
    private const int MAX_TITLE = 120;
    private const int MAX_BODY = 400;
    /** The answer's shape, after the translated rules (braces would be placeholders in a message). */
    private const string SHAPE = '{"insights": [{"title": "…", "body": "…", "sources": ["tool_name"]}]}';

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

        return $set->visibleTo(array_map(static fn (Vehicle $v): int => $v->id, $this->kit->fleet($user)));
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

        return $set->visibleTo(array_map(static fn (Vehicle $v): int => $v->id, $this->kit->fleet($user)));
    }

    private function find(User $user, AiSession $session): AiInsightSet
    {
        $context = $this->context($user);
        $system = $this->translator->trans('ai_insights.system.text', ['max' => self::MAX_INSIGHTS]) . "\n" . self::SHAPE . "\n\n"
            . $this->translator->trans('ask.system.context') . "\n" . $context;
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
            $insights[] = new AiInsight(
                $title,
                $body,
                $cited,
                $this->grounding->ungrounded($title . "\n" . $body, $sources, $user->preferences->locale),
            );
        }

        return $insights;
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
