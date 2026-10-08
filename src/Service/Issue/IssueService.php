<?php

declare(strict_types=1);

namespace Logbook\Service\Issue;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueData;
use Logbook\Domain\Issue\IssueSource;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\Issue\IssueUpdate;
use Logbook\Domain\Issue\IssueUpdateData;
use Logbook\Domain\Issue\IssueUpdateReason;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\IssueRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Odometer\OdometerWarning;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

/**
 * The issues log (spec.md §7.37): logging and editing an issue with its
 * odometer reading and files, its status changes (each written to the
 * timeline), its updates, and fixing it: by the service records linked from
 * either side, or without one.
 *
 * Callers pass a Vehicle already resolved for the signed-in user. Logbook
 * records the owner's words; nothing here judges what a fault is.
 */
final readonly class IssueService
{
    /** A dated issue's or update's reading is placed at local noon, as a service record's. */
    private const string READING_TIME = 'T12:00';

    public function __construct(
        private IssueRepository $issues,
        private MaintenanceEntryRepository $records,
        private OdometerService $odometer,
        private AttachmentService $attachments,
        private AccessContext $author,
        private Transaction $transaction,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<IssueStatus> $statuses none = every status
     * @return list<Issue> safety first, then newest noticed first
     */
    public function list(Vehicle $vehicle, array $statuses = []): array
    {
        return $this->issues->listForVehicle($vehicle->id, $statuses);
    }

    /**
     * @param list<int> $vehicleIds
     * @param list<IssueStatus> $statuses none = every status
     * @return list<Issue>
     */
    public function listFor(array $vehicleIds, array $statuses = []): array
    {
        return $this->issues->listForVehicles($vehicleIds, $statuses);
    }

    /**
     * Open and watching issues: the overview card, the fleet page and the
     * *Fixes* checklist.
     *
     * @return list<Issue>
     */
    public function unresolved(Vehicle $vehicle): array
    {
        return $this->issues->listForVehicle($vehicle->id, [IssueStatus::Open, IssueStatus::Watching]);
    }

    /**
     * @throws IssueNotFound
     */
    public function get(Vehicle $vehicle, int $id): Issue
    {
        return $this->issues->find($vehicle->id, $id)
            ?? throw new IssueNotFound(sprintf('Issue %d not found.', $id));
    }

    public function find(Vehicle $vehicle, int $id): ?Issue
    {
        return $this->issues->find($vehicle->id, $id);
    }

    /**
     * @param DateTimeZone $zone the owner's zone: where a reading's noon is
     */
    public function create(
        Vehicle $vehicle,
        IssueData $data,
        DateTimeZone $zone,
        PendingUploads $files = new PendingUploads(),
        IssueSource $source = IssueSource::Manual,
        ?string $sourceRef = null,
    ): Issue {
        $data = $data->withStatus($data->status === IssueStatus::Watching ? IssueStatus::Watching : IssueStatus::Open);
        $id = $this->attachments->saveWithFiles($files, function (array $stored) use (
            $vehicle,
            $data,
            $zone,
            $source,
            $sourceRef,
        ): int {
            $by = $this->author->authorId() ?? $vehicle->userId;
            $id = $this->issues->insert($vehicle->id, $data, $this->clock->now(), $by, $source, $sourceRef);
            $this->recordReading($vehicle, OdometerSource::Issue, $id, $data->odometerKm, $data->noticedOn, $zone);
            $this->attachments->record($vehicle, AttachmentOwner::Issue, $id, $stored);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    /**
     * Save the edit form. A fixed issue stays fixed (*Mark fixed* and *It's
     * back* change that); otherwise a change between open and watching is
     * written to the timeline.
     */
    public function update(
        Vehicle $vehicle,
        Issue $issue,
        IssueData $data,
        DateTimeZone $zone,
        PendingUploads $files = new PendingUploads(),
    ): Issue {
        $status = match (true) {
            $issue->isFixed() => IssueStatus::Fixed,
            $data->status === IssueStatus::Watching => IssueStatus::Watching,
            default => IssueStatus::Open,
        };
        $data = $data->withStatus($status);
        $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $issue, $data, $zone): void {
            $this->issues->update($vehicle->id, $issue->id, $data, $this->clock->now());
            $this->recordReading($vehicle, OdometerSource::Issue, $issue->id, $data->odometerKm, $data->noticedOn, $zone);
            $this->attachments->record($vehicle, AttachmentOwner::Issue, $issue->id, $stored);
            if ($data->status !== $issue->status()) {
                $this->automatic($issue, $issue->status(), $data->status, IssueUpdateReason::Edited, $zone);
            }
        });

        return $this->get($vehicle, $issue->id);
    }

    /**
     * Delete an issue with its fixes, updates, readings and files. The
     * service records that fixed it are kept.
     */
    public function delete(Vehicle $vehicle, Issue $issue): void
    {
        $this->transaction->run(function () use ($vehicle, $issue): void {
            foreach ($this->issues->updatesOf($issue->id) as $update) {
                $this->odometer->forgetEntry($vehicle, OdometerSource::IssueUpdate, $update->id);
            }
            $this->odometer->forgetEntry($vehicle, OdometerSource::Issue, $issue->id);
            $this->issues->delete($vehicle->id, $issue->id);
        });
        $this->attachments->deleteForOwner($vehicle, AttachmentOwner::Issue, $issue->id);
    }

    /**
     * *Watch* (and *Watch again*): keep an eye on it, with an optional
     * look-again date and/or odometer (canonical km).
     */
    public function watch(Vehicle $vehicle, Issue $issue, ?DateTimeImmutable $on, ?string $km, DateTimeZone $zone): Issue
    {
        $this->transaction->run(function () use ($vehicle, $issue, $on, $km, $zone): void {
            $this->issues->update($vehicle->id, $issue->id, $issue->data->watching($on, $km), $this->clock->now());
            if ($issue->isFixed()) {
                $this->issues->setFixed($vehicle->id, $issue->id, null, null, $this->clock->now());
            }
            $this->automatic($issue, $issue->status(), IssueStatus::Watching, IssueUpdateReason::Watch, $zone);
        });

        return $this->get($vehicle, $issue->id);
    }

    /**
     * Back to open: *Stop watching*, *Reopen* from *Look again*, and *It's
     * back* on a fixed issue, which keeps its links (the earlier fix is
     * history).
     */
    public function reopen(Vehicle $vehicle, Issue $issue, DateTimeZone $zone): Issue
    {
        if ($issue->status() === IssueStatus::Open) {
            return $issue;
        }
        $reason = match ($issue->status()) {
            IssueStatus::Fixed => IssueUpdateReason::Back,
            default => IssueUpdateReason::Reopened,
        };
        $this->transaction->run(function () use ($vehicle, $issue, $reason, $zone): void {
            $this->issues->update($vehicle->id, $issue->id, $issue->data->withStatus(IssueStatus::Open), $this->clock->now());
            $this->issues->setFixed($vehicle->id, $issue->id, null, null, $this->clock->now());
            $this->automatic($issue, $issue->status(), IssueStatus::Open, $reason, $zone);
        });

        return $this->get($vehicle, $issue->id);
    }

    /**
     * *Stop watching*: back to open, the look-again point cleared.
     */
    public function stopWatching(Vehicle $vehicle, Issue $issue, DateTimeZone $zone): Issue
    {
        if ($issue->status() !== IssueStatus::Watching) {
            return $issue;
        }
        $this->transaction->run(function () use ($vehicle, $issue, $zone): void {
            $this->issues->update($vehicle->id, $issue->id, $issue->data->withStatus(IssueStatus::Open), $this->clock->now());
            $this->automatic($issue, IssueStatus::Watching, IssueStatus::Open, IssueUpdateReason::StopWatching, $zone);
        });

        return $this->get($vehicle, $issue->id);
    }

    /**
     * *Looked at it*: *Done* on the look-again reminder (#311). The point is
     * cleared and the issue stays watching.
     */
    public function lookedAt(Vehicle $vehicle, Issue $issue, DateTimeZone $zone): void
    {
        if ($issue->status() !== IssueStatus::Watching || !$issue->data->hasLookAgain()) {
            return;
        }
        $this->transaction->run(function () use ($vehicle, $issue, $zone): void {
            $this->issues->update($vehicle->id, $issue->id, $issue->data->watching(null, null), $this->clock->now());
            $this->automatic($issue, IssueStatus::Watching, IssueStatus::Watching, IssueUpdateReason::LookedAt, $zone);
        });
    }

    /**
     * *Fixed without a record* (#308): some faults just stop. The date and
     * the owner's note go on the timeline; unlinking a record later never
     * reopens it.
     */
    public function fixWithoutRecord(Vehicle $vehicle, Issue $issue, DateTimeImmutable $on, ?string $note): Issue
    {
        if ($issue->isFixed()) {
            return $issue;
        }
        $this->transaction->run(function () use ($vehicle, $issue, $on, $note): void {
            $now = $this->clock->now();
            $this->issues->update($vehicle->id, $issue->id, $issue->data->withStatus(IssueStatus::Fixed), $now);
            $this->issues->setFixed($vehicle->id, $issue->id, $on, null, $now);
            $this->issues->insertUpdate(
                $issue->id,
                $on,
                null,
                $note,
                $issue->status(),
                IssueStatus::Fixed,
                IssueUpdateReason::FixedWithoutRecord,
                $this->author->authorId(),
                $now,
            );
        });

        return $this->get($vehicle, $issue->id);
    }

    /**
     * Link service records of the vehicle as the issue's fix (*Link an
     * existing record*, or the API).
     *
     * @param list<int> $recordIds
     */
    public function fixWith(Vehicle $vehicle, Issue $issue, array $recordIds): Issue
    {
        $this->transaction->run(function () use ($vehicle, $issue, $recordIds): void {
            foreach ($recordIds as $recordId) {
                $record = $this->records->find($vehicle->id, $recordId);
                if ($record !== null) {
                    $this->link($vehicle, $this->get($vehicle, $issue->id), $record);
                }
            }
        });

        return $this->get($vehicle, $issue->id);
    }

    /**
     * Make a service record fix exactly these issues of its vehicle (the
     * *Fixes* checklist on save): ticked ones are linked and fixed on the
     * record's date, unticked ones unlinked, and the others' fixed date
     * follows the record; an unlink is dated the owner's today. Call inside
     * the record's transaction.
     *
     * @param list<int> $issueIds
     */
    public function setFixesOf(Vehicle $vehicle, MaintenanceEntry $record, array $issueIds, DateTimeZone $zone): void
    {
        $today = LocalTime::today($this->clock, $zone);
        $current = $this->issues->fixedBy($record->id);
        foreach ($current as $issueId) {
            if (!in_array($issueId, $issueIds, true)) {
                $issue = $this->issues->find($vehicle->id, $issueId);
                if ($issue !== null) {
                    $this->unlink($vehicle, $issue, $record->id, IssueUpdateReason::RecordUnlinked, $today);
                }
            }
        }
        foreach (array_unique($issueIds) as $issueId) {
            $issue = $this->issues->find($vehicle->id, $issueId);
            if ($issue !== null) {
                $this->link($vehicle, $issue, $record);
            }
        }
    }

    /**
     * Before a service record is deleted: each issue it fixed loses the link
     * and, with none left, goes back to the status it had (spec.md §7.37
     * *Unlinking*). Call inside the record's transaction.
     */
    public function recordDeleted(Vehicle $vehicle, int $recordId, DateTimeZone $zone): void
    {
        $today = LocalTime::today($this->clock, $zone);
        foreach ($this->issues->fixedBy($recordId) as $issueId) {
            $issue = $this->issues->find($vehicle->id, $issueId);
            if ($issue !== null) {
                $this->unlink($vehicle, $issue, $recordId, IssueUpdateReason::RecordDeleted, $today);
            }
        }
    }

    /**
     * The ids of the records that fixed an issue.
     *
     * @return list<MaintenanceEntry> oldest link first
     */
    public function fixesOf(Vehicle $vehicle, Issue $issue): array
    {
        $records = [];
        foreach ($this->issues->fixesOf($issue->id) as $recordId) {
            $record = $this->records->find($vehicle->id, $recordId);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Every fix link of these issues, in one query.
     *
     * @param list<Issue> $issues
     * @return array<int, list<int>> record ids by issue id
     */
    public function fixLinks(array $issues): array
    {
        return $this->issues->fixesFor(array_map(static fn (Issue $i): int => $i->id, $issues));
    }

    /**
     * @return list<int> the issue ids a service record fixes
     */
    public function fixedBy(int $recordId): array
    {
        return $this->issues->fixedBy($recordId);
    }

    /**
     * The records an issue can be linked to: the vehicle's, dated on or
     * after it was noticed, newest first.
     *
     * @return list<MaintenanceEntry>
     */
    public function linkableRecords(Vehicle $vehicle, Issue $issue): array
    {
        $linked = $this->issues->fixesOf($issue->id);

        return array_values(array_filter(
            $this->records->listForVehicle($vehicle->id),
            static fn (MaintenanceEntry $r): bool => $r->data->performedOn >= $issue->data->noticedOn
                && !in_array($r->id, $linked, true),
        ));
    }

    /**
     * @return list<IssueUpdate> oldest first
     */
    public function updatesOf(Issue $issue): array
    {
        return $this->issues->updatesOf($issue->id);
    }

    /**
     * @throws IssueNotFound
     */
    public function getUpdate(Issue $issue, int $id): IssueUpdate
    {
        return $this->issues->findUpdate($issue->id, $id)
            ?? throw new IssueNotFound(sprintf('Update %d not found.', $id));
    }

    /**
     * *Add update*: a note, an odometer and an optional status change. A
     * change to fixed is not made here (*Mark fixed* does it).
     */
    public function addUpdate(Vehicle $vehicle, Issue $issue, IssueUpdateData $data, DateTimeZone $zone): IssueUpdate
    {
        $id = $this->transaction->run(function () use ($vehicle, $issue, $data, $zone): int {
            $now = $this->clock->now();
            $to = $data->status;
            $changed = $to !== null && $to !== IssueStatus::Fixed && $to !== $issue->status();
            if ($changed) {
                $next = $to === IssueStatus::Watching
                    ? $issue->data->watching($data->lookAgainOn, $data->lookAgainKm)
                    : $issue->data->withStatus($to);
                $this->issues->update($vehicle->id, $issue->id, $next, $now);
                if ($issue->isFixed()) {
                    $this->issues->setFixed($vehicle->id, $issue->id, null, null, $now);
                }
            }
            $id = $this->issues->insertUpdate(
                $issue->id,
                $data->notedOn,
                $data->odometerKm,
                $data->note,
                $changed ? $issue->status() : null,
                $changed ? $to : null,
                $changed ? IssueUpdateReason::Edited : null,
                $this->author->authorId() ?? $vehicle->userId,
                $now,
            );
            $this->recordReading($vehicle, OdometerSource::IssueUpdate, $id, $data->odometerKm, $data->notedOn, $zone);

            return $id;
        });

        return $this->getUpdate($issue, $id);
    }

    /**
     * Edit a note's date, odometer and text (#316). An automatic line is
     * never edited.
     */
    public function editUpdate(
        Vehicle $vehicle,
        Issue $issue,
        IssueUpdate $update,
        IssueUpdateData $data,
        DateTimeZone $zone,
    ): void {
        if ($update->isAutomatic()) {
            return;
        }
        $this->transaction->run(function () use ($vehicle, $issue, $update, $data, $zone): void {
            $now = $this->clock->now();
            $this->issues->updateNote($issue->id, $update->id, $data->notedOn, $data->odometerKm, $data->note, $now);
            $this->recordReading($vehicle, OdometerSource::IssueUpdate, $update->id, $data->odometerKm, $data->notedOn, $zone);
        });
    }

    /**
     * Delete a note with its reading (#316). An automatic line is never deleted.
     */
    public function deleteUpdate(Vehicle $vehicle, Issue $issue, IssueUpdate $update): void
    {
        if ($update->isAutomatic()) {
            return;
        }
        $this->transaction->run(function () use ($vehicle, $issue, $update): void {
            $this->odometer->forgetEntry($vehicle, OdometerSource::IssueUpdate, $update->id);
            $this->issues->deleteUpdate($issue->id, $update->id);
        });
    }

    /**
     * Plausibility warning for the issue's own reading.
     */
    public function odometerWarning(Vehicle $vehicle, Issue $issue): ?OdometerWarning
    {
        return $this->odometer->warningForEntry($vehicle, OdometerSource::Issue, $issue->id);
    }

    /**
     * Plausibility warning for an update's reading.
     */
    public function updateOdometerWarning(Vehicle $vehicle, IssueUpdate $update): ?OdometerWarning
    {
        return $this->odometer->warningForEntry($vehicle, OdometerSource::IssueUpdate, $update->id);
    }

    /**
     * Link one record as a fix: an unfixed issue becomes fixed on the
     * record's date, remembering the status to return to; a fixed one keeps
     * the latest fixing record's date.
     */
    private function link(Vehicle $vehicle, Issue $issue, MaintenanceEntry $record): void
    {
        $now = $this->clock->now();
        $this->issues->addFix($issue->id, $record->id, $now);
        if (!$issue->isFixed()) {
            $this->issues->update($vehicle->id, $issue->id, $issue->data->withStatus(IssueStatus::Fixed), $now);
            $this->issues->setFixed($vehicle->id, $issue->id, $record->data->performedOn, $issue->status(), $now);
            $this->issues->insertUpdate(
                $issue->id,
                $record->data->performedOn,
                null,
                null,
                $issue->status(),
                IssueStatus::Fixed,
                IssueUpdateReason::Fixed,
                $this->author->authorId(),
                $now,
            );

            return;
        }
        $this->refreshFixedOn($vehicle, $this->get($vehicle, $issue->id));
    }

    /**
     * Take one record's link away. With none left, an issue fixed by records
     * goes back to its earlier status (a look-again point is not restored);
     * one fixed without a record stays fixed.
     */
    private function unlink(Vehicle $vehicle, Issue $issue, int $recordId, IssueUpdateReason $reason, DateTimeImmutable $on): void
    {
        $now = $this->clock->now();
        $this->issues->removeFix($issue->id, $recordId);
        if (!$issue->isFixed() || $issue->statusBeforeFix === null) {
            return;
        }
        if ($this->issues->fixesOf($issue->id) !== []) {
            $this->refreshFixedOn($vehicle, $issue, $recordId);

            return;
        }
        $back = $issue->statusBeforeFix;
        $this->issues->update($vehicle->id, $issue->id, $issue->data->withStatus($back), $now);
        $this->issues->setFixed($vehicle->id, $issue->id, null, null, $now);
        $by = $this->author->authorId();
        $this->issues->insertUpdate($issue->id, $on, null, null, IssueStatus::Fixed, $back, $reason, $by, $now);
    }

    /**
     * A fixed-by-records issue is fixed on its latest fixing record's date.
     */
    private function refreshFixedOn(Vehicle $vehicle, Issue $issue, ?int $without = null): void
    {
        if ($issue->statusBeforeFix === null) {
            return;
        }
        $latest = null;
        foreach ($this->issues->fixesOf($issue->id) as $recordId) {
            $record = $recordId === $without ? null : $this->records->find($vehicle->id, $recordId);
            if ($record !== null && ($latest === null || $record->data->performedOn > $latest)) {
                $latest = $record->data->performedOn;
            }
        }
        if ($latest !== null && $latest != $issue->fixedOn) {
            $this->issues->setFixed($vehicle->id, $issue->id, $latest, $issue->statusBeforeFix, $this->clock->now());
        }
    }

    /**
     * An automatic status-change line, dated the owner's today.
     */
    private function automatic(
        Issue $issue,
        IssueStatus $from,
        IssueStatus $to,
        IssueUpdateReason $reason,
        DateTimeZone $zone,
    ): void {
        $this->issues->insertUpdate(
            $issue->id,
            LocalTime::today($this->clock, $zone),
            null,
            null,
            $from,
            $to,
            $reason,
            $this->author->authorId(),
            $this->clock->now(),
        );
    }

    /**
     * Create, move or remove the reading an issue or update owns, at local
     * noon on its date.
     */
    private function recordReading(
        Vehicle $vehicle,
        OdometerSource $source,
        int $id,
        ?string $km,
        DateTimeImmutable $on,
        DateTimeZone $zone,
    ): void {
        $at = LocalTime::toUtc($on->format('Y-m-d') . self::READING_TIME, $zone)
            ?? DateTimeImmutable::createFromInterface($on);
        $this->odometer->recordForEntry($vehicle, $source, $id, $km, $at);
    }
}
