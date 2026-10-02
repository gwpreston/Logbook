<?php

declare(strict_types=1);

namespace Logbook\Action\Backup;

use Logbook\Service\Backup\BackupService;
use Logbook\Service\Jobs\ScheduledBackups;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders Settings → Backup (shared by the page and a refused restore).
 */
final readonly class BackupPage
{
    /** Session key: the token of the backup staged for restore. */
    public const string RESTORE_TOKEN = 'restore_token';

    public function __construct(
        private View $view,
        private AppSettings $settings,
        private ScheduledBackups $scheduled,
    ) {
    }

    /**
     * @param array{key: string, params: array<string, string>}|null $error why a restore was refused
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?array $error = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'backup/index.twig', [
            'available' => BackupService::isAvailable(),
            'error' => $error,
            'max_restore_mb' => $this->settings->maxRestoreMb,
            'backup_path' => $this->settings->backupPath,
            'scheduled' => $this->scheduled->list(),
        ], $status);
    }
}
