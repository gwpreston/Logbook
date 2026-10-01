<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use Logbook\Domain\Ai\Ask\AskMessage;
use Logbook\Domain\Ai\Ask\AskRole;
use Logbook\Domain\Ai\Ask\AskThread;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\Draft\AiDraft;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\User\User;
use Logbook\Service\Ai\Draft\DraftStore;
use Logbook\Service\Vehicle\VehicleNotFound;
use Logbook\Service\Vehicle\VehicleService;
use Psr\Clock\ClockInterface;
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
        private DraftStore $drafts,
        private VehicleService $vehicles,
        private ClockInterface $clock,
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
            'turns' => $thread === null ? [] : $this->turns($user, $this->threads->messages($thread)),
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
        $now = $this->clock->now();
        $cards = [];
        foreach ($this->drafts->many($user, $ids) as $draft) {
            try {
                $vehicle = $this->vehicles->get($user, $draft->vehicleId);
            } catch (VehicleNotFound) {
                $vehicle = null;
            }
            $vehicleArgs = ['id' => (string) $draft->vehicleId];
            [$view, $edit, $editQuery] = match ($draft->kind) {
                DraftKind::Fuel => [['fuel.index', $vehicleArgs], ['fuel.create', $vehicleArgs], []],
                DraftKind::Odometer => [['odometer.index', $vehicleArgs], ['odometer.create', $vehicleArgs], []],
                DraftKind::Maintenance => [['maintenance.index', $vehicleArgs], ['maintenance.create', $vehicleArgs], []],
                DraftKind::Document => [['compliance.index', $vehicleArgs], ['compliance.create', $vehicleArgs], []],
                DraftKind::Expense => [['expenses.index', $vehicleArgs], ['expenses.create', $vehicleArgs], []],
                DraftKind::TyreCheck => [
                    ['tyres.index', $vehicleArgs],
                    ['tyres.change', $vehicleArgs + ['kind' => 'check']],
                    [],
                ],
                DraftKind::Reminder => [['reminders.index', []], ['reminders.create', []], ['vehicle' => $draft->vehicleId]],
            };
            $cards[$draft->id] = [
                'draft' => $draft,
                'state' => $draft->state($now)->value,
                'card' => $draft->card,
                'vehicle' => $vehicle,
                'can_undo' => $draft->canUndo($now),
                'undo_seconds' => max(
                    0,
                    AiDraft::UNDO_SECONDS - ($now->getTimestamp() - ($draft->appliedAt?->getTimestamp() ?? 0)),
                ),
                'view' => $view,
                'edit' => [$edit[0], $edit[1], $editQuery + ['draft' => $draft->id]],
            ];
        }

        return $cards;
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
