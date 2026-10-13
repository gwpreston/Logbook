<?php

declare(strict_types=1);

namespace Logbook\Action\Backup;

use Logbook\Service\Backup\BackupWarning;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/backup/warning — *Warn me after* (7, 14, 30 or 90 days,
 * or *Never*) and *I back up another way* (spec.md §7.13).
 */
final readonly class SaveBackupWarningAction
{
    public function __construct(
        private BackupWarning $warning,
        private BackupPage $page,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = RequestContext::form($request);
        $value = $form['days'] ?? '';
        $days = null;
        if ($value !== 'never') {
            $days = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
            if (!is_int($days) || !in_array($days, BackupWarning::CHOICES, true)) {
                return $this->page->render($request, $response, status: 422, warningError: [
                    'key' => 'backup.warning.days_invalid',
                    'params' => [],
                ]);
            }
        }

        $this->warning->save($days, ($form['elsewhere'] ?? '') === '1', RequestContext::requireUser($request));
        RequestContext::session($request)->flash('success', 'backup.warning.saved');

        return $this->redirect->toRoute('backup.index');
    }
}
