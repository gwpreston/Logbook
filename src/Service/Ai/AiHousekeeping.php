<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Repository\AiBusyRepository;
use Logbook\Repository\AiDraftRepository;
use Logbook\Repository\AiProgressRepository;
use Logbook\Repository\AiRequestRepository;
use Logbook\Repository\AiThreadRepository;
use Logbook\Repository\PendingUploadRepository;
use Logbook\Support\Storage\FileStorage;
use Psr\Clock\ClockInterface;

/**
 * The scheduled task's AI part (spec.md §7.25): usage rows older than 90
 * days are deleted, and locks left by requests that died are cleared. The
 * monthly cap needs no reset: it is summed from this month's rows. From
 * Phase 26.2 (§7.26) each user's Ask threads go once their last message is
 * older than the user's retention, and progress rows after an hour; from
 * Phase 26.4 (§7.27) scanned files left unclaimed for 24 hours. This
 * runs whether or not the reminders module is on.
 */
final readonly class AiHousekeeping
{
    public const int RETENTION_DAYS = 90;
    public const int PROGRESS_MINUTES = 60;

    public function __construct(
        private AiRequestRepository $requests,
        private AiBusyRepository $busy,
        private AiThreadRepository $threads,
        private AiProgressRepository $progress,
        private AiDraftRepository $drafts,
        private AiPreferences $preferences,
        private ClockInterface $clock,
        private PendingUploadRepository $pendingUploads,
        private FileStorage $files,
    ) {
    }

    /**
     * @return int usage rows deleted
     */
    public function run(): int
    {
        $now = $this->clock->now();
        $this->busy->deleteExpired($now);
        $this->progress->deleteBefore($now->modify(sprintf('-%d minutes', self::PROGRESS_MINUTES)));
        // Drafts (Phase 26.3): unapplied ones once expired, applied ones a day after Add.
        $this->drafts->deleteExpired($now);
        // Scanned files no entry claimed (Phase 26.4): 24 hours, then they and their files go.
        foreach ($this->pendingUploads->expired($now) as $upload) {
            $this->files->delete($upload->storedPath);
            $this->pendingUploads->delete($upload->id);
        }
        foreach ($this->threads->userIds() as $userId) {
            $days = $this->preferences->retentionDays($userId);
            $this->threads->deleteBefore($userId, $now->modify(sprintf('-%d days', $days)));
        }

        return $this->requests->deleteBefore($now->modify(sprintf('-%d days', self::RETENTION_DAYS)));
    }
}
