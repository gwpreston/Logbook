<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Jobs;

use Logbook\Repository\JobRunRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /settings/jobs/runs/{run}/status — the run's status and output so
 * far, as JSON, polled every 2 seconds by its page until it finishes.
 */
final readonly class JobRunStatusAction
{
    public function __construct(private JobRunRepository $runs)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $run = $this->runs->find((int) ($args['run'] ?? 0)) ?? throw new HttpNotFoundException($request);

        $response->getBody()->write(json_encode([
            'status' => $run->status->value,
            'finished' => $run->status->isFinished(),
            'output' => $run->output,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
