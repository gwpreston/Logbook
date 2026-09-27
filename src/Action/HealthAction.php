<?php

declare(strict_types=1);

namespace Logbook\Action;

use Logbook\Service\Health\HealthCheck;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /health — 200 when the app and database are reachable, 503 otherwise.
 */
final readonly class HealthAction
{
    public function __construct(private HealthCheck $healthCheck)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $report = $this->healthCheck->run();

        $response->getBody()->write(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $response
            ->withStatus($report->isHealthy() ? 200 : 503)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
