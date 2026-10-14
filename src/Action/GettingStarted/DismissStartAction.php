<?php

declare(strict_types=1);

namespace Logbook\Action\GettingStarted;

use Logbook\Service\GettingStarted\GettingStartedService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /vehicles/start/dismiss — leave *Set up your first vehicle* for good
 * (spec.md §7.9): the card goes from the overview and the flow is not offered
 * again. What was saved stays.
 */
final readonly class DismissStartAction
{
    public function __construct(private GettingStartedService $start, private Redirector $redirect)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->start->dismiss(RequestContext::requireUser($request));

        return $this->redirect->backOr($request, 'garage');
    }
}
