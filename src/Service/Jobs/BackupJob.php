<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Logbook\Service\Backup\BackupService;
use Logbook\Support\Display\DisplayFormatter;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `backup` (spec.md §7.30 *Scheduled backups*): off by default, else
 * daily or weekly; *Run now* works whatever the schedule. It writes a
 * `logbook-scheduled-…zip` to BACKUP_PATH, then keeps only the newest N
 * scheduled files.
 */
final readonly class BackupJob implements Job
{
    public const string NAME = 'backup';

    public function __construct(
        private BackupService $backups,
        private ScheduledBackups $files,
        private JobSettings $settings,
        private DisplayFormatter $formatter,
        private TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function interval(): ?int
    {
        return $this->settings->backupSchedule()->interval();
    }

    public function run(JobContext $context): JobResult
    {
        if (!BackupService::isAvailable()) {
            return JobResult::failed($this->translator->trans('backup.unavailable'));
        }
        $dir = $this->files->directory();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create %s (BACKUP_PATH).', $dir));
        }

        $name = $this->files->newName();
        $manifest = $this->backups->create($dir . '/' . $name);
        $size = (int) filesize($dir . '/' . $name);
        $context->logger->info('Backup written to {file} ({vehicles} vehicle(s), {files} file(s), {size} bytes).', [
            'file' => $dir . '/' . $name,
            'vehicles' => $manifest->rows('vehicles'),
            'files' => $manifest->files,
            'size' => $size,
        ]);

        $deleted = $this->files->prune($this->settings->backupKeep());
        foreach ($deleted as $old) {
            $context->logger->info('Deleted the old scheduled backup {file}.', ['file' => $old]);
        }

        return JobResult::ok($this->translator->trans('jobs.summary.backup', [
            'file' => $name,
            'size' => $this->formatter->fileSize($size),
            'deleted' => count($deleted),
        ]), ['size' => $size, 'deleted' => count($deleted)]);
    }
}
