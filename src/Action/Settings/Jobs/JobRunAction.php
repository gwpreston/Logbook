<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Jobs;

use Logbook\Domain\Job\JobStatus;
use Logbook\Repository\JobRunRepository;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /settings/jobs/runs/{run} — one run: job, trigger, who, times,
 * status, summary and output (spec.md §7.30). A run that found its job
 * locked links to the run that held it. While running, the page polls
 * its status (assets/js/jobs.js).
 */
final readonly class JobRunAction
{
    public function __construct(
        private JobRunRepository $runs,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $run = $this->runs->find((int) ($args['run'] ?? 0)) ?? throw new HttpNotFoundException($request);

        return $this->view->render($request, $response, 'settings/jobs/run.twig', [
            'run' => $run,
            'holder' => $run->status === JobStatus::SkippedLocked ? $this->runs->holderOf($run) : null,
        ]);
    }
}
