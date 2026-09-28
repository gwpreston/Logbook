<?php

declare(strict_types=1);

namespace Logbook\Action\Backup;

use Logbook\Service\Backup\BackupService;
use Logbook\Service\Backup\InvalidBackup;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\StagedFiles;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /settings/backup/restore — step 1 of a restore: check the uploaded
 * backup completely, keep it, and ask for confirmation. Nothing changes yet.
 */
final readonly class UploadRestoreAction
{
    public function __construct(
        private BackupService $backups,
        private BackupPage $page,
        private StagedFiles $staged,
        private AppSettings $settings,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $file = $request->getUploadedFiles()['backup'] ?? null;
        if (!$file instanceof UploadedFileInterface || !FileUpload::wasProvided($file)) {
            return $this->refuse($request, $response, 'backup.error.no_file');
        }
        $tooLarge = ['max' => (string) $this->settings->maxRestoreMb];
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->refuse($request, $response, 'backup.error.upload_too_large', $tooLarge);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->refuse($request, $response, 'upload.failed');
        }
        if (($file->getSize() ?? 0) > $this->settings->maxRestoreMb * 1024 * 1024) {
            return $this->refuse($request, $response, 'backup.error.upload_too_large', $tooLarge);
        }
        if (!BackupService::isAvailable()) {
            return $this->refuse($request, $response, 'backup.unavailable');
        }

        $token = $this->staged->stage($file, 'restore');
        $path = $this->staged->path('restore', $token);
        try {
            if ($path === null) {
                throw new InvalidBackup('upload.failed');
            }
            $this->backups->inspect($path);
        } catch (InvalidBackup $e) {
            $this->staged->discard('restore', $token);

            return $this->refuse($request, $response, $e->key, $e->params);
        }

        $session = RequestContext::session($request);
        $previous = $session->get(BackupPage::RESTORE_TOKEN);
        if (is_string($previous)) {
            $this->staged->discard('restore', $previous);
        }
        $session->set(BackupPage::RESTORE_TOKEN, $token);

        return $this->redirect->toRoute('backup.restore.confirm', ['token' => $token]);
    }

    /**
     * @param array<string, string> $params
     */
    private function refuse(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $key,
        array $params = [],
    ): ResponseInterface {
        return $this->page->render($request, $response, ['key' => $key, 'params' => $params], 422);
    }
}
