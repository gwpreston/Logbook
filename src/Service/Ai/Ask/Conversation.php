<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Ask\AskMessage;
use Logbook\Domain\Ai\Ask\AskRole;
use Logbook\Domain\Ai\Ask\AskThread;
use Logbook\Domain\Ai\Ask\ToolRun;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AiProgressRepository;
use Logbook\Repository\AiThreadRepository;
use Logbook\Service\Ai\AiFailure;
use Logbook\Service\Ai\AiGateway;
use Logbook\Service\Ai\AiSession;
use Logbook\Service\Ai\Provider\ChatMessage;
use Logbook\Service\Ai\Provider\ChatRequest;
use Logbook\Service\Ai\Provider\ChatResult;
use Logbook\Service\Ai\Provider\FinishReason;
use Logbook\Support\Display\UserDisplayScope;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One Ask Logbook question, start to finish (spec.md §7.26 *Loop*): the
 * context, the earlier turns of the thread, up to 8 tool calls, the answer,
 * the grounding check, and the thread kept. The whole question holds the
 * user's one-at-a-time lock. The model only ever sees the system text,
 * the context below and what the tools return.
 */
final readonly class Conversation
{
    public const int MAX_TOOL_CALLS = 8;
    /** Model calls at most: the tool calls, then two rounds to answer. */
    public const int MAX_ROUNDS = self::MAX_TOOL_CALLS + 2;
    /** No new model call starts after this long; the answer so far is a timeout. */
    public const int DEADLINE_SECONDS = 240;
    /** Bytes of earlier tool results carried into a follow-up, newest kept. */
    public const int HISTORY_BUDGET = 24000;
    /** Earlier questions and answers carried, newest kept. */
    public const int HISTORY_TURNS = 6;
    public const string ENOUGH = 'Answer with what you have.';

    public function __construct(
        private AiGateway $gateway,
        private ToolRegistry $tools,
        private ToolKit $kit,
        private GroundingCheck $grounding,
        private AiThreadRepository $threads,
        private AiProgressRepository $progress,
        private UserDisplayScope $display,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Ask a question, in a new thread or as a follow-up.
     *
     * @throws AiFailure when nothing could start (AI off, no model, busy, …); nothing is kept then
     */
    public function ask(User $user, ?AskThread $thread, string $question, ?string $progressToken = null): AskOutcome
    {
        $question = trim($question);
        if ($progressToken !== null) {
            $this->progress->start($user->id, $progressToken, $this->clock->now());
        }
        $threadId = $thread?->id;
        try {
            $outcome = $this->display->run($user, fn (): AskOutcome => $this->gateway->session(
                $user,
                AiTaskName::Ask,
                self::DEADLINE_SECONDS,
                fn (AiSession $session): AskOutcome => $this->converse($user, $session, $thread, $question, $progressToken),
            ));
            $threadId = $outcome->thread->id;

            return $outcome;
        } finally {
            if ($progressToken !== null) {
                $this->progress->finish($progressToken, $threadId, $this->clock->now());
            }
        }
    }

    private function converse(
        User $user,
        AiSession $session,
        ?AskThread $thread,
        string $question,
        ?string $token,
    ): AskOutcome {
        $earlier = $thread === null ? [] : $this->threads->messages($thread);
        $thread ??= $this->threads->create($user->id, $question, $this->clock->now());
        $asked = $this->threads->addMessage($thread, AskRole::User, $question, $this->clock->now());

        $fleet = $this->kit->fleet($user);
        $context = $this->context($user, $fleet);
        $carried = $this->carriedRuns($earlier, $fleet);
        $system = $this->system($context, $carried);
        $messages = [...$this->history($earlier, $fleet), ChatMessage::user($question)];
        $definitions = $this->tools->definitions($user);

        $runs = [];
        $started = hrtime(true);
        $text = null;
        $error = null;
        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            if ((hrtime(true) - $started) / 1e9 > self::DEADLINE_SECONDS) {
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
                if ($text === '' && $result->finishReason !== FinishReason::Stop) {
                    $error = ErrorCode::BadResponse;
                }
                break;
            }
            $messages[] = $result->toMessage();
            foreach ($this->runTools($user, $result, $runs, $token, $thread->id) as [$call, $content]) {
                $messages[] = ChatMessage::toolResult($call, $content);
            }
        }
        if ($text === null && $error === null) {
            // Still calling tools after every round.
            $error = ErrorCode::BadResponse;
        }
        if ($text === '' && $error === null) {
            $error = ErrorCode::BadResponse;
        }

        $sources = [$question, $context, ...array_map(static fn (ToolRun $r): string => $r->content(), [...$carried, ...$runs])];
        $ungrounded = $error === null
            ? $this->grounding->ungrounded((string) $text, $sources, $user->preferences->locale)
            : [];
        $answer = $this->threads->addMessage(
            $thread,
            AskRole::Assistant,
            $error === null ? (string) $text : '',
            $this->clock->now(),
            $runs,
            $ungrounded,
            $session->connection->name,
            $session->connection->location,
            $session->model,
            $error,
        );

        return new AskOutcome($thread, $asked, $answer);
    }

    /**
     * Run a round's tool calls, within the limit; calls past it are told
     * to answer.
     *
     * @param list<ToolRun> $runs every run so far, appended to
     * @param-out list<ToolRun> $runs
     * @return list<array{\Logbook\Service\Ai\Provider\ToolCall, string}>
     */
    private function runTools(User $user, ChatResult $result, array &$runs, ?string $token, int $threadId): array
    {
        $replies = [];
        foreach ($result->toolCalls as $call) {
            if (count($runs) >= self::MAX_TOOL_CALLS) {
                $replies[] = [$call, json_encode(['error' => self::ENOUGH], JSON_THROW_ON_ERROR)];
                continue;
            }
            if ($token !== null) {
                $this->progress->tools(
                    $token,
                    [...array_map(static fn (ToolRun $r): string => $r->name, $runs), $call->name],
                    $this->clock->now(),
                );
            }
            $run = $this->tools->run($user, $call, $threadId);
            $runs[] = $run;
            $replies[] = [$call, $run->content()];
        }

        return $replies;
    }

    /**
     * What the model knows before any tool: today, the user's language,
     * units and currency, and the vehicles they can see.
     *
     * @param list<Vehicle> $fleet
     */
    private function context(User $user, array $fleet): string
    {
        $preferences = $user->preferences;

        return json_encode([
            'today' => $this->kit->today($user)->format('Y-m-d'),
            'time_zone' => $preferences->timezone,
            'locale' => $preferences->locale,
            'distance_unit' => $preferences->distanceUnit->value,
            'volume_unit' => $preferences->volumeUnit->value,
            'consumption_unit' => $preferences->consumptionUnit->value,
            'currency' => $preferences->currency,
            'vehicles' => array_map($this->kit->vehicleRow(...), $fleet),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<ToolRun> $carried
     */
    private function system(string $context, array $carried): string
    {
        $text = $this->translator->trans('ask.system.text') . "\n\n"
            . $this->translator->trans('ask.system.context') . "\n" . $context;
        if ($carried !== []) {
            $text .= "\n\n" . $this->translator->trans('ask.system.earlier') . "\n" . json_encode(
                array_map(static fn (ToolRun $r): array => [
                    'tool' => $r->name,
                    'arguments' => $r->arguments,
                    'result' => json_decode($r->content(), true),
                ], $carried),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );
        }

        return $text;
    }

    /**
     * The thread's earlier questions and answers: failed ones left out, and
     * those that drew on a vehicle the user can no longer see.
     *
     * @param list<AskMessage> $earlier
     * @param list<Vehicle> $fleet
     * @return list<ChatMessage>
     */
    private function history(array $earlier, array $fleet): array
    {
        $visible = self::ids($fleet);
        $turns = [];
        $question = null;
        foreach ($earlier as $message) {
            if ($message->role === AskRole::User) {
                $question = $message->content;
                continue;
            }
            if ($question !== null && $message->isAnswer() && self::allVisible($message->toolRuns, $visible)) {
                $turns[] = [ChatMessage::user($question), ChatMessage::assistant($message->content)];
            }
            $question = null;
        }

        return array_merge(...array_slice($turns, -self::HISTORY_TURNS));
    }

    /**
     * Earlier tool results to carry into a follow-up: newest first within
     * the budget, and none about a vehicle the user can no longer see.
     *
     * @param list<AskMessage> $earlier
     * @param list<Vehicle> $fleet
     * @return list<ToolRun> oldest first
     */
    private function carriedRuns(array $earlier, array $fleet): array
    {
        $visible = self::ids($fleet);
        $kept = [];
        $bytes = 0;
        foreach (array_reverse($earlier) as $message) {
            foreach (array_reverse($message->toolRuns) as $run) {
                if ($run->result === null || !self::allVisible([$run], $visible)) {
                    continue;
                }
                $bytes += strlen($run->content());
                if ($bytes > self::HISTORY_BUDGET) {
                    break 2;
                }
                $kept[] = $run;
            }
        }

        return array_reverse($kept);
    }

    /**
     * @param list<Vehicle> $fleet
     * @return array<int, true>
     */
    private static function ids(array $fleet): array
    {
        $ids = [];
        foreach ($fleet as $vehicle) {
            $ids[$vehicle->id] = true;
        }

        return $ids;
    }

    /**
     * @param list<ToolRun> $runs
     * @param array<int, true> $visible
     */
    private static function allVisible(array $runs, array $visible): bool
    {
        foreach ($runs as $run) {
            foreach ($run->vehicleIds as $id) {
                if (!isset($visible[$id])) {
                    return false;
                }
            }
        }

        return true;
    }
}
