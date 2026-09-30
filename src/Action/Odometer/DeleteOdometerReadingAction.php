<?php

declare(strict_types=1);

namespace Logbook\Action\Odometer;

use Logbook\Action\EntryGuard;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /vehicles/{id}/odometer/{reading}/delete — confirm (works
 * without JS), then delete a manual reading.
 */
final readonly class DeleteOdometerReadingAction
{
    public function __construct(
        private OdometerService $odometer,
        private DisplayFormatter $formatter,
        private View $view,
        private Redirector $redirect,
        private EntryGuard $guard,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $reading = OdometerRoute::reading($this->odometer, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $reading->createdBy);
        if (!$reading->isManual()) {
            throw new HttpNotFoundException($request);
        }
        $description = [
            'reading' => $this->formatter->distance($reading->readingKm),
            'date' => $this->formatter->instantDate($reading->recordedAt),
        ];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'odometer.delete_title',
                'body' => 'odometer.delete_body',
                'params' => $description,
                'action' => ['odometer.delete', ['id' => $vehicle->id, 'reading' => $reading->id]],
                'cancel' => ['odometer.index', ['id' => $vehicle->id]],
                'active_tab' => 'odometer',
            ]);
        }

        $this->odometer->delete($vehicle, $reading);
        RequestContext::session($request)->flash('success', 'odometer.deleted', $description);

        return $this->redirect->toRoute('odometer.index', ['id' => (string) $vehicle->id]);
    }
}
