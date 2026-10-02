<?php

declare(strict_types=1);

namespace Logbook\Action\Scheduler;

use Logbook\Domain\Job\JobTrigger;
use Logbook\Service\Jobs\JobSettings;
use Logbook\Service\Jobs\SchedulerHealth;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\LongRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /_scheduler/tick — *On page visits* (spec.md §7.30): a signed-in
 * page's beacon. When the trigger is on and a pass is due, runs one in
 * this request (the visitor's page never waits for it; the pass lock
 * keeps two beacons to one pass). 204 either way; 404 while it is off.
 */
final readonly class SchedulerTickAction
{
    public function __construct(
        private JobSettings $settings,
        private SchedulerHealth $health,
        private ScheduledTasks $tasks,
        private AppSettings $app,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->settings->pageVisits()) {
            throw new HttpNotFoundException($request);
        }
        if ($this->health->isPassDue()) {
            LongRequest::allow($this->app->jobTimeLimit);
            $this->tasks->run(JobTrigger::PageVisit, null, $this->health->isPassDue(...));
        }

        return $response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }
}
