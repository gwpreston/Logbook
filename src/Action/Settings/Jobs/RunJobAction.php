<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Jobs;

use Logbook\Domain\Job\JobTrigger;
use Logbook\Service\Jobs\JobRegistry;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Http\LongRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /settings/jobs/{job}/run — *Run now* (spec.md §7.30): runs the job
 * in this request, then redirects to its run page. The job keeps going if
 * the browser goes away (or a proxy gives up), within JOB_TIME_LIMIT.
 * Sessions are database rows with no lock, so other pages stay usable
 * meanwhile. With JS the page posts in the background and follows the
 * new run (assets/js/jobs.js).
 */
final readonly class RunJobAction
{
    public function __construct(
        private JobRegistry $registry,
        private JobRunner $runner,
        private AppSettings $settings,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $job = $this->registry->get($args['job'] ?? '') ?? throw new HttpNotFoundException($request);
        $user = RequestContext::requireUser($request);

        LongRequest::allow($this->settings->jobTimeLimit);
        $run = $this->runner->run($job, JobTrigger::Manual, $user->id, null, $this->settings->jobTimeLimit);

        return $this->redirect->toRoute('settings.jobs.run', ['run' => (string) $run->id]);
    }
}
