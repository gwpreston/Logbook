<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Service\Part\InsurerList;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/parts/insurer — *For your insurer* (spec.md §7.39,
 * #438): the current modifications to read out or print for a quote or a
 * renewal, each with when it was declared. `View` only, and never a cost.
 */
final readonly class InsurerListAction
{
    public function __construct(
        private InsurerList $list,
        private View $view,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $rows = $this->list->forVehicle($vehicle);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());

        return $this->view->render($request, $response, 'parts/insurer.twig', [
            'vehicle' => $vehicle,
            'rows' => $rows,
            'undeclared' => count(array_filter($rows, static fn ($row): bool => !$row->isDeclared())),
            'reverted' => $this->list->revertedCount($vehicle),
            'today' => $today,
            'values' => ['declared_on' => $today->format('Y-m-d')],
            'errors' => [],
        ]);
    }
}
