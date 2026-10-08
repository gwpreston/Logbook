<?php

declare(strict_types=1);

namespace Logbook\Action\MotHistory;

use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\MotHistoryFetcher;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /vehicles/{id}/mot-history/stop — *Stop and remove* (spec.md §7.38),
 * `Own` only: the stored tests, their defects and readings, the recall
 * state and the confirmation go; issues and documents made from them stay.
 */
final readonly class StopMotHistoryAction
{
    public function __construct(
        private MotHistoryConfig $config,
        private MotHistoryFetcher $fetcher,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!$this->config->enabled()) {
            throw new HttpNotFoundException($request);
        }
        $vehicle = RequestContext::vehicle($request);
        $this->fetcher->stop($vehicle);
        RequestContext::session($request)->flash('success', 'mot_history.stop.done');

        return $this->redirect->toRoute('mot_history.show', ['id' => (string) $vehicle->id]);
    }
}
