<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use Logbook\Domain\Ai\Ask\AskMessage;
use Logbook\Domain\Ai\Ask\AskRole;
use Logbook\Domain\Ai\Ask\AskThread;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\User\User;
use Logbook\Repository\AiThreadRepository;
use Logbook\Service\Ai\AiPreferences;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the Ask page shows (spec.md §7.26 *Answer page*): the threads, the
 * open one as questions and answers with their sources, who answers, and
 * the retention setting.
 */
final readonly class AskPage
{
    public function __construct(
        private AiThreadRepository $threads,
        private AskAvailability $availability,
        private AiPreferences $preferences,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function context(User $user, ?AskThread $thread, ?string $question = null, ?string $error = null): array
    {
        $connection = $this->availability->connection();

        return [
            'connection' => $connection,
            'threads' => $this->threads->forUser($user->id),
            'thread' => $thread,
            'turns' => $thread === null ? [] : $this->turns($this->threads->messages($thread)),
            'retention_days' => $this->preferences->retentionDays($user->id),
            'retention_choices' => AiPreferences::RETENTION_CHOICES,
            'question' => $question ?? '',
            'error' => $error,
            'progress_token' => bin2hex(random_bytes(16)),
        ];
    }

    /**
     * @param list<AskMessage> $messages
     * @return list<array<string, mixed>>
     */
    private function turns(array $messages): array
    {
        $turns = [];
        $question = null;
        foreach ($messages as $message) {
            if ($message->role === AskRole::User) {
                if ($question !== null) {
                    $turns[] = ['question' => $question, 'answer' => null, 'error' => null, 'sources' => []];
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
            ];
            $question = null;
        }
        if ($question !== null) {
            $turns[] = ['question' => $question, 'answer' => null, 'error' => null, 'sources' => []];
        }

        return $turns;
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
