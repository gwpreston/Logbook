<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Jobs;

use Logbook\Service\Jobs\BackupSchedule;
use Logbook\Service\Jobs\JobSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/jobs/backup — *Scheduled backups*: off, daily or weekly,
 * and how many to keep (1–60; spec.md §7.30).
 */
final readonly class JobBackupScheduleAction
{
    public function __construct(
        private JobSettings $settings,
        private JobsPage $page,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = RequestContext::form($request);
        $scheduleValue = $form['schedule'] ?? '';
        $schedule = is_string($scheduleValue) ? BackupSchedule::tryFrom($scheduleValue) : null;
        $keepValue = $form['keep'] ?? '';
        $keep = is_string($keepValue) ? filter_var(trim($keepValue), FILTER_VALIDATE_INT) : false;

        $errors = [];
        if ($schedule === null) {
            $errors['schedule'] = ['key' => 'jobs.backup.schedule_invalid', 'params' => []];
        }
        if ($keep === false || $keep < BackupSchedule::KEEP_MIN || $keep > BackupSchedule::KEEP_MAX) {
            $errors['keep'] = ['key' => 'jobs.backup.keep_invalid', 'params' => [
                'min' => BackupSchedule::KEEP_MIN,
                'max' => BackupSchedule::KEEP_MAX,
            ]];
        }
        if ($schedule === null || $keep === false || $errors !== []) {
            return $this->page->render($request, $response, [
                'backup_errors' => $errors,
                'backup_values' => [
                    'schedule' => is_string($scheduleValue) ? $scheduleValue : '',
                    'keep' => is_string($keepValue) ? $keepValue : '',
                ],
            ], 422);
        }

        $this->settings->setBackup($schedule, $keep);
        RequestContext::session($request)->flash('success', 'jobs.backup.saved');

        return $this->redirect->toRoute('settings.jobs');
    }
}
