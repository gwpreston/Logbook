<?php

declare(strict_types=1);

namespace Logbook\Action\Odometer;

use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the add/edit reading form (shared by both Actions).
 */
final readonly class OdometerFormPage
{
    public function __construct(
        private View $view,
        private OdometerService $odometer,
    ) {
    }

    /**
     * @param array<string, string> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        array $values,
        ?OdometerReading $reading = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'odometer/form.twig', [
            'vehicle' => $vehicle,
            'reading' => $reading,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'latest' => $this->odometer->history($vehicle)->latest(),
        ], $status);
    }
}
