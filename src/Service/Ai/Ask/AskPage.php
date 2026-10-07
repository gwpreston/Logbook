<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use Logbook\Domain\Ai\Ask\AskMessage;
use Logbook\Domain\Ai\Ask\AskRole;
use Logbook\Domain\Ai\Ask\AskThread;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\User\User;
use Logbook\Service\Ai\Draft\DraftCards;
use Logbook\Service\Ai\Draft\DraftStore;
use Logbook\Repository\AiThreadRepository;
use Logbook\Service\Ai\AiPreferences;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What Ask shows on the Insights page and a thread's page (spec.md §7.26,
 * Phase 38): *Your questions* with the retention setting, and the open
 * thread as questions and answers with their sources and who answers.
 */
final readonly class AskPage
{
    /** *Your questions* shows this many; the rest sit under *Show all* (#276). */
    public const int SHOWN = 5;

    public function __construct(
        private AiThreadRepository $threads,
        private AskAvailability $availability,
        private AiPreferences $preferences,
        private TranslatorInterface $translator,
        private DraftStore $drafts,
        private DraftCards $draftCards,
    ) {
    }

    /**
     * The Insights page's *Your questions* (#272, #275, #276).
     *
     * @return array<string, mixed>
     */
    public function questions(User $user): array
    {
        return [
            'threads' => $this->threads->forUser($user->id),
            'threads_shown' => self::SHOWN,
            'retention_days' => $this->preferences->retentionDays($user->id),
            'retention_choices' => AiPreferences::RETENTION_CHOICES,
        ];
    }

    /**
     * A thread's page (#274): the conversation and the follow-up box.
     *
     * @return array<string, mixed>
     */
    public function thread(User $user, AskThread $thread, ?string $question = null, ?string $error = null): array
    {
        return [
            'connection' => $this->availability->connection(),
            'thread' => $thread,
            'turns' => $this->turns($user, $this->threads->messages($thread)),
            'question' => $question ?? '',
            'error' => $error,
            'progress_token' => bin2hex(random_bytes(16)),
        ];
    }

    /**
     * @param list<AskMessage> $messages
     * @return list<array<string, mixed>>
     */
    private function turns(User $user, array $messages): array
    {
        $cards = $this->cards($user, $messages);
        $turns = [];
        $question = null;
        foreach ($messages as $message) {
            if ($message->role === AskRole::User) {
                if ($question !== null) {
                    $turns[] = ['question' => $question, 'answer' => null, 'error' => null, 'sources' => [], 'drafts' => []];
                }
                $question = $message;
                continue;
            }
            $turns[] = [
                'question' => $question,
                'answer' => $message,
                'error' => $message->error === null ? null : $this->errorText($message),
                'sources' => array_values(array_filter(
                    array_map(static fn ($run) => $run->result, $message->toolRuns),
                    static fn ($result): bool => $result !== null,
                )),
                'drafts' => array_values(array_filter(array_map(
                    static fn ($run): ?array => $cards[self::draftId($run->result->data ?? [])] ?? null,
                    $message->toolRuns,
                ))),
            ];
            $question = null;
        }
        if ($question !== null) {
            $turns[] = ['question' => $question, 'answer' => null, 'error' => null, 'sources' => [], 'drafts' => []];
        }

        return $turns;
    }

    /**
     * The draft cards of a thread's answers (spec.md §7.26 *Draft card*), by
     * draft id: what Logbook formatted, where the card stands, and the
     * routes behind *View* and *Edit*.
     *
     * @param list<AskMessage> $messages
     * @return array<int, array<string, mixed>>
     */
    private function cards(User $user, array $messages): array
    {
        $ids = [];
        foreach ($messages as $message) {
            foreach ($message->toolRuns as $run) {
                $id = self::draftId($run->result->data ?? []);
                if ($id !== 0) {
                    $ids[] = $id;
                }
            }
        }

        return $this->draftCards->cards($user, $this->drafts->many($user, $ids));
    }

    /**
     * @param array<string, mixed> $data a tool result's data
     */
    private static function draftId(array $data): int
    {
        return is_int($data['draft_id'] ?? null) ? $data['draft_id'] : 0;
    }

    private function errorText(AskMessage $message): string
    {
        $parameters = [
            'connection' => $message->connectionName ?? '',
            'model' => $message->model ?? '',
            'seconds' => 0,
            'variable' => '',
        ];
        $key = $message->error === ErrorCode::Timeout
            ? 'ask.error.timeout'
            : ($message->error?->messageKey() ?? 'ai.error.provider');

        return $this->translator->trans($key, $parameters);
    }
}
