<?php

declare(strict_types=1);

namespace Logbook\Action\Backup;

use Logbook\Repository\UserRepository;
use Logbook\Service\Backup\BackupAge;
use Logbook\Service\Backup\BackupService;
use Logbook\Service\Backup\BackupWarning;
use Logbook\Service\Jobs\JobSettings;
use Logbook\Service\Jobs\ScheduledBackups;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders Settings → Backup (shared by the page, a refused restore and a
 * refused warning form): the last backup's age, the schedule, the warning
 * (spec.md §7.13), the download and the restore.
 */
final readonly class BackupPage
{
    /** Session key: the token of the backup staged for restore. */
    public const string RESTORE_TOKEN = 'restore_token';

    public function __construct(
        private View $view,
        private AppSettings $settings,
        private ScheduledBackups $scheduled,
        private BackupAge $age,
        private JobSettings $jobs,
        private UserRepository $users,
    ) {
    }

    /**
     * @param array{key: string, params: array<string, string>}|null $error why a restore was refused
     * @param array{key: string, params: array<string, string>}|null $warningError why the warning form was refused
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?array $error = null,
        int $status = 200,
        ?array $warningError = null,
    ): ResponseInterface {
        $backup = $this->age->for(RequestContext::requireUser($request));
        $elsewhereBy = $backup->elsewhere === null ? null : $this->users->find($backup->elsewhere['by']);

        return $this->view->render($request, $response, 'backup/index.twig', [
            'available' => BackupService::isAvailable(),
            'error' => $error,
            'max_restore_mb' => $this->settings->maxRestoreMb,
            'backup_path' => $this->settings->backupPath,
            'scheduled' => $this->scheduled->list(),
            'backup' => $backup,
            'elsewhere_by' => $elsewhereBy?->displayName,
            'schedule' => $this->jobs->backupSchedule()->value,
            'keep' => $this->jobs->backupKeep(),
            'warn_choices' => BackupWarning::CHOICES,
            'warning_error' => $warningError,
        ], $status);
    }
}
