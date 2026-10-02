<?php

declare(strict_types=1);

namespace Logbook\Action\Scheduler;

use Logbook\Domain\Job\JobTrigger;
use Logbook\Service\Jobs\JobSettings;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Support\Config\AppSettings;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /cron/{token} — *External URL* (spec.md §7.30): a scheduler
 * pass (only due jobs) for a service that calls a URL on a schedule. The
 * token is the authentication; no session. 404 for a wrong token or with
 * the trigger off; 429 within 60 seconds of the last accepted call.
 */
final readonly class SchedulerUrlAction
{
    public const int SPACING = 60;

    public function __construct(
        private JobSettings $settings,
        private ScheduledTasks $tasks,
        private ClockInterface $clock,
        private AppSettings $app,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!$this->settings->url() || !$this->settings->urlTokenMatches($args['token'] ?? '')) {
            throw new HttpNotFoundException($request);
        }

        $now = $this->clock->now();
        $last = $this->settings->urlLastCall();
        $since = $last === null ? null : $now->getTimestamp() - $last->getTimestamp();
        if ($since !== null && $since >= 0 && $since < self::SPACING) {
            $response->getBody()->write("Too soon: call at most once a minute.\n");

            return $response->withStatus(429)
                ->withHeader('Retry-After', (string) (self::SPACING - $since))
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store');
        }
        $this->settings->markUrlCall($now);

        ignore_user_abort(true);
        set_time_limit($this->app->jobTimeLimit);
        $summary = $this->tasks->run(JobTrigger::Url);
        $response->getBody()->write(($summary->wasLocked
            ? 'Another pass is running; nothing to do.'
            : ucfirst($summary->describe()) . '.') . "\n");

        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
