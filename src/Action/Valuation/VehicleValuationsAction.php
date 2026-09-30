<?php

declare(strict_types=1);

namespace Logbook\Action\Valuation;

use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/valuations — a vehicle's valuations newest first
 * (spec.md §7.1), with *Add valuation* and *Export CSV*. Not a tab: it is
 * reached from the overview's *Ownership* card and the vehicle form.
 */
final readonly class VehicleValuationsAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ValuationService $valuations,
        private AttachmentService $attachments,
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

        return $this->view->render($request, $response, 'valuations/index.twig', [
            'vehicle' => $vehicle,
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
            'valuations' => array_reverse($this->valuations->forVehicle($vehicle)),
            'attachment_counts' => $this->attachments->counts($vehicle),
        ]);
    }
}
