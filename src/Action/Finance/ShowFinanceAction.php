<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/finance/{agreement} — the agreement page (spec.md
 * §7.32 *Agreement page*): its figures, then the schedule with *Mark
 * missed* and *Mark paid late*, extra payments, settlement quotes, the
 * consistency check and the overlap warning. Printable.
 */
final readonly class ShowFinanceAction
{
    public function __construct(
        private FinanceService $finance,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $agreement = FinanceRoute::agreement($this->finance, $user, $vehicle, $request, $args);
        $view = $this->finance->view($user, $vehicle, $agreement);
        // Each missed mark by the date it concerns, for its *Undo*.
        $marks = [];
        foreach ($view->events as $event) {
            if ($event->kind === PaymentEventKind::Missed && $event->dueOn !== null) {
                $marks[$event->dueOn->format('Y-m-d')] = $event->id;
            }
        }

        return $this->view->render($request, $response, 'finance/show.twig', [
            'vehicle' => $vehicle,
            'finance' => $view,
            'marks' => $marks,
            'today' => $this->finance->ownerToday($user, $vehicle),
            'open' => FinanceService::isOpen($agreement) && !$vehicle->isArchived(),
        ]);
    }
}
