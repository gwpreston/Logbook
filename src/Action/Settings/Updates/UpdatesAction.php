<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Updates;

use Logbook\Repository\JobRunRepository;
use Logbook\Service\Jobs\JobRegistry;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Service\Updates\UpdateCheckJob;
use Logbook\Service\Updates\UpdateMessages;
use Logbook\Service\Updates\UpdateSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Version\InstalledVersion;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /settings/updates — Settings → Updates (spec.md §7.31), admins
 * only: *Check for updates* (off by default), *Show update banner*, the
 * installed version and the last result. *Check now* posts to the Jobs
 * page's *Run now* for `update_check`. With `UPDATE_CHECK_ALLOWED=false`
 * the page is a 404.
 */
final readonly class UpdatesAction
{
    public function __construct(
        private UpdateSettings $settings,
        private UpdateMessages $messages,
        private InstalledVersion $installed,
        private JobRegistry $jobs,
        private JobRunner $runner,
        private JobRunRepository $runs,
        private ClockInterface $clock,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $job = $this->jobs->get(UpdateCheckJob::NAME);
        if (!$this->settings->allowed() || $job === null) {
            throw new HttpNotFoundException($request);
        }

        if ($request->getMethod() === 'POST') {
            $input = RequestContext::form($request);
            $this->settings->save(($input['check'] ?? '') === '1', ($input['banner'] ?? '') === '1');
            RequestContext::session($request)->flash('success', 'updates.saved');

            return $this->redirect->toRoute('settings.updates');
        }

        $status = $this->settings->status();
        $checking = $this->settings->checking();

        return $this->view->render($request, $response, 'settings/updates/index.twig', [
            'repo' => $this->settings->repository(),
            'checking' => $checking,
            'banner' => $this->settings->banner(),
            'installed' => $this->installed->version,
            'status' => $status,
            'result' => $status->latest === null && $status->error === null
                ? null
                : $this->messages->summary($status, $this->installed->semVer(), $this->installed->version),
            'error' => $status->error === null ? null : $this->messages->error($status->error),
            'next' => $checking ? $this->runner->nextRun($job, $this->clock->now()) : null,
            'last_run' => $this->runs->latestWorked(UpdateCheckJob::NAME),
            'newest_id' => $this->runs->newestId(),
        ]);
    }
}
