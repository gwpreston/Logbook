<?php

declare(strict_types=1);

namespace Logbook\Action\Backup;

use Logbook\Service\Backup\BackupService;
use Logbook\Service\Backup\InvalidBackup;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Storage\StagedFiles;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /settings/backup/restore/{token} — step 2 of a restore: show what
 * the backup holds and, once "replace all data" is ticked, restore it
 * (spec.md §7.13). A safety backup of the current data is written first.
 * Afterwards every session has ended: sign in with the restored account.
 */
final readonly class ConfirmRestoreAction
{
    public function __construct(
        private BackupService $backups,
        private StagedFiles $staged,
        private BackupPage $page,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = RequestContext::session($request);
        $token = $args['token'] ?? '';
        $path = $session->get(BackupPage::RESTORE_TOKEN) === $token ? $this->staged->path('restore', $token) : null;
        if ($path === null) {
            throw new HttpNotFoundException($request);
        }

        try {
            $manifest = $this->backups->inspect($path);
        } catch (InvalidBackup $e) {
            return $this->page->render($request, $response, ['key' => $e->key, 'params' => $e->params], 422);
        }

        $posted = $request->getMethod() === 'POST';
        if (!$posted || (RequestContext::form($request)['confirm'] ?? '') !== '1') {
            return $this->view->render($request, $response, 'backup/confirm.twig', [
                'manifest' => $manifest,
                'token' => $token,
                'not_confirmed' => $posted,
            ], $posted ? 422 : 200);
        }

        try {
            $this->backups->restore($path);
        } catch (InvalidBackup $e) {
            return $this->page->render($request, $response, ['key' => $e->key, 'params' => $e->params], 422);
        } finally {
            $this->staged->discard('restore', $token);
        }

        // The accounts were replaced and every stored session deleted: start
        // a fresh, signed-out session just to carry the message.
        $session->destroy();
        $session->flash('success', 'backup.restored');

        return $this->redirect->toRoute('login');
    }
}
