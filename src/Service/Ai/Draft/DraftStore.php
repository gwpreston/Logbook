<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Draft;

use Logbook\Service\Incident\IncidentNotFound;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Issue\IssueNotFound;
use Logbook\Service\Issue\IssueService;
use DateTimeImmutable;
use Logbook\Domain\Ai\Draft\AiDraft;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\Ai\Draft\DraftProposal;
use Logbook\Domain\Ai\Draft\DraftSource;
use Logbook\Domain\Ai\Draft\DraftState;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AiDraftRepository;
use Logbook\Service\Compliance\ComplianceDocumentNotFound;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Expense\ExpenseEntryNotFound;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Fuel\FuelEntryNotFound;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Maintenance\MaintenanceEntryNotFound;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerReadingNotFound;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderNotFound;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Tyre\TyreChangeNotFound;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Display\UserDisplayScope;
use Psr\Clock\ClockInterface;

/**
 * Ask's drafts from card to entry (spec.md §7.26 *Drafting entries*):
 * kept when a tool validates one, re-validated and written when the user
 * presses *Add*, closed by *Discard*, and undone for a few seconds after.
 *
 * *Add* claims the draft and writes the entry in one transaction, so a
 * double press saves once. The write is the API's, judged on the data as
 * it is at the press, with the access the user has then.
 */
final readonly class DraftStore
{
    public function __construct(
        private AiDraftRepository $drafts,
        private DraftWriter $writer,
        private Transaction $transaction,
        private UserDisplayScope $display,
        private FuelService $fuel,
        private OdometerService $odometer,
        private MaintenanceService $maintenance,
        private ComplianceService $compliance,
        private ExpenseService $expenses,
        private TyreChangeService $tyreChanges,
        private ReminderService $reminders,
        private ClockInterface $clock,
        private IncidentService $incidents,
        private IssueService $issues,
    ) {
    }

    public function create(User $user, ?int $threadId, DraftProposal $proposal, DraftSource $source = DraftSource::Ask): int
    {
        return $this->drafts->insert($user->id, $threadId, $proposal, $this->clock->now(), $source);
    }

    /**
     * The drafts an MCP client left for review (spec.md §7.28 *Drafts to
     * review*): those waiting for *Add*, and those added in the last few
     * seconds, whose card still offers *Undo*. Newest first.
     *
     * @return list<AiDraft>
     */
    public function toReview(User $user): array
    {
        $now = $this->clock->now();

        return $this->drafts->pending(
            $user->id,
            DraftSource::Mcp,
            $now,
            $now->modify('-' . AiDraft::UNDO_SECONDS . ' seconds'),
        );
    }

    /**
     * The user's own draft; another user's is not found.
     *
     * @throws DraftNotFound
     */
    public function get(User $user, int $id): AiDraft
    {
        return $this->drafts->find($user->id, $id) ?? throw new DraftNotFound();
    }

    /**
     * @param list<int> $ids
     * @return array<int, AiDraft>
     */
    public function many(User $user, array $ids): array
    {
        return $this->drafts->findMany($user->id, $ids);
    }

    /**
     * What the thread's drafts became, for a follow-up's context ("the
     * conversation notes what was added", spec.md §7.26).
     *
     * @return list<array{draft_id: int, kind: string, state: string, summary: string}>
     */
    public function notes(User $user, int $threadId): array
    {
        $now = $this->clock->now();

        return array_map(static fn (AiDraft $draft): array => [
            'draft_id' => $draft->id,
            'kind' => $draft->kind->value,
            'state' => ($draft->card['duplicate'] ?? false) === true ? 'already_logged' : $draft->state($now)->value,
            'summary' => is_string($draft->card['summary'] ?? null) ? $draft->card['summary'] : '',
        ], $this->drafts->forThread($user->id, $threadId));
    }

    /**
     * *Add*: write the draft as it stands now, once.
     *
     * @throws DraftNotFound|DraftRefused|DraftInvalid
     */
    public function apply(User $user, int $id): DraftApplied
    {
        $draft = $this->get($user, $id);
        if ($draft->state($this->clock->now()) !== DraftState::Waiting) {
            throw new DraftRefused('ask.draft.refused.closed');
        }

        return $this->display->run($user, fn (): DraftApplied => $this->transaction->run(function () use (
            $user,
            $draft,
        ): DraftApplied {
            if (!$this->drafts->claim($draft->id, $this->clock->now())) {
                throw new DraftRefused('ask.draft.refused.closed');
            }
            $vehicle = $this->writer->vehicle($user, $draft->kind, $draft->vehicleId);
            $written = $this->writer->write($user, $vehicle, $draft->kind, $draft->input);
            // An entry already logged is not this card's: there is nothing to undo.
            if ($written->duplicate) {
                $this->drafts->updateCard($draft->id, ['duplicate' => true] + $draft->card);
            }
            $this->drafts->recordEntry(
                $draft->id,
                $written->duplicate ? null : $written->entryId,
                $written->duplicate ? null : $written->updatedAt,
            );

            return new DraftApplied($this->get($user, $draft->id), $vehicle, $written);
        }));
    }

    /**
     * *Discard*: close a waiting draft; nothing is written.
     *
     * @throws DraftNotFound|DraftRefused
     */
    public function discard(User $user, int $id): void
    {
        $draft = $this->get($user, $id);
        $now = $this->clock->now();
        if ($draft->state($now) !== DraftState::Waiting || !$this->drafts->discard($user->id, $id, $now)) {
            throw new DraftRefused('ask.draft.refused.closed');
        }
    }

    /**
     * *Undo*: delete the entry *Add* wrote, through the normal delete path,
     * within a few seconds and only while it is untouched.
     *
     * @throws DraftNotFound|DraftRefused
     */
    public function undo(User $user, int $id): AiDraft
    {
        $draft = $this->get($user, $id);
        $now = $this->clock->now();
        if (!$draft->canUndo($now) || $draft->appliedEntryId === null) {
            throw new DraftRefused('ask.draft.refused.undo_late');
        }
        $vehicle = $this->writer->vehicle($user, $draft->kind, $draft->vehicleId);
        $this->transaction->run(function () use ($user, $draft, $vehicle, $now): void {
            $entryId = (int) $draft->appliedEntryId;
            if (!$this->deleteIfUntouched($user, $vehicle, $draft->kind, $entryId, $draft->appliedUpdatedAt)) {
                throw new DraftRefused('ask.draft.refused.undo_changed');
            }
            $this->drafts->discard($user->id, $draft->id, $now);
        });

        return $this->get($user, $draft->id);
    }

    /**
     * The *Edit* form saved this draft's entry: the card is closed as added.
     */
    public function closeByForm(User $user, int $id): void
    {
        $this->drafts->closeByForm($user->id, $id, $this->clock->now());
    }

    /**
     * The scheduled clean-up (spec.md §6 AiDraft).
     */
    public function deleteExpired(): int
    {
        return $this->drafts->deleteExpired($this->clock->now());
    }

    private function deleteIfUntouched(
        User $user,
        Vehicle $vehicle,
        DraftKind $kind,
        int $entryId,
        ?DateTimeImmutable $updatedAt,
    ): bool {
        $untouched = static fn (DateTimeImmutable $current): bool => $updatedAt !== null
            && $current->format('Y-m-d H:i:s') === $updatedAt->format('Y-m-d H:i:s');
        try {
            switch ($kind) {
                case DraftKind::Fuel:
                    $entry = $this->fuel->get($vehicle, $entryId);
                    if (!$untouched($entry->updatedAt)) {
                        return false;
                    }
                    $this->fuel->delete($vehicle, $entry);
                    break;
                case DraftKind::Odometer:
                    $reading = $this->odometer->get($vehicle, $entryId);
                    if (!$untouched($reading->updatedAt)) {
                        return false;
                    }
                    $this->odometer->delete($vehicle, $reading);
                    break;
                case DraftKind::Maintenance:
                    $record = $this->maintenance->get($vehicle, $entryId);
                    if (!$untouched($record->updatedAt)) {
                        return false;
                    }
                    $this->maintenance->delete($vehicle, $record, $user->preferences->timeZone());
                    break;
                case DraftKind::Document:
                    $document = $this->compliance->get($vehicle, $entryId);
                    if (!$untouched($document->updatedAt)) {
                        return false;
                    }
                    $this->compliance->delete($vehicle, $document);
                    break;
                case DraftKind::Expense:
                    $expense = $this->expenses->get($vehicle, $entryId);
                    if (!$untouched($expense->updatedAt)) {
                        return false;
                    }
                    $this->expenses->delete($vehicle, $expense);
                    break;
                case DraftKind::Incident:
                    $incident = $this->incidents->get($vehicle, $entryId);
                    if (!$untouched($incident->updatedAt)) {
                        return false;
                    }
                    $this->incidents->delete($vehicle, $incident);
                    break;
                case DraftKind::Issue:
                    $issue = $this->issues->get($vehicle, $entryId);
                    if (!$untouched($issue->updatedAt)) {
                        return false;
                    }
                    $this->issues->delete($vehicle, $issue);
                    break;
                case DraftKind::TyreCheck:
                    $check = $this->tyreChanges->get($vehicle, $entryId);
                    if (!$untouched($check->updatedAt)) {
                        return false;
                    }
                    $this->tyreChanges->delete($vehicle, $check);
                    break;
                case DraftKind::Reminder:
                    $reminder = $this->reminders->get($user, $entryId);
                    if ($reminder->vehicleId !== $vehicle->id || !$untouched($reminder->updatedAt)) {
                        return false;
                    }
                    $this->reminders->deleteManual($reminder);
                    break;
            }
        } catch (
            FuelEntryNotFound
            | OdometerReadingNotFound
            | MaintenanceEntryNotFound
            | ComplianceDocumentNotFound
            | ExpenseEntryNotFound
            | TyreChangeNotFound
            | ReminderNotFound
            | IncidentNotFound
            | IssueNotFound
        ) {
            return false;
        }

        return true;
    }
}
