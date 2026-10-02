<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Jobs;

use Logbook\Repository\JobRunRepository;
use Logbook\Service\Jobs\JobRegistry;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /settings/jobs/{job}/started?after={id} — the run this admin's *Run
 * now* just started, as JSON (`{"url": …}` once its row exists, else `null`), polled
 * by the Jobs page so it can open the run page while the job goes on.
 */
final readonly class JobStartedAction
{
    public function __construct(
        private JobRegistry $registry,
        private JobRunRepository $runs,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $job = $this->registry->get($args['job'] ?? '') ?? throw new HttpNotFoundException($request);
        $after = $request->getQueryParams()['after'] ?? '0';
        $run = $this->runs->newestManualAfter(
            $job->name(),
            RequestContext::requireUser($request)->id,
            is_string($after) && ctype_digit($after) ? (int) $after : 0,
        );

        $response->getBody()->write(json_encode([
            'url' => $run === null ? null : $this->redirect->urlFor('settings.jobs.run', ['run' => (string) $run->id]),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
