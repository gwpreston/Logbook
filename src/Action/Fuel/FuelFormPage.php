<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the log/edit fill-up form (shared by both Actions).
 */
final readonly class FuelFormPage
{
    public function __construct(
        private View $view,
        private OdometerService $odometer,
        private AttachmentUpload $upload,
    ) {
    }

    /**
     * @param array<string, string> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        string $currency,
        array $values,
        ?FuelEntry $entry = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'fuel/form.twig', [
            'vehicle' => $vehicle,
            'entry' => $entry,
            'currency' => $currency,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'fuels' => Fuel::cases(),
            'latest' => $this->odometer->history($vehicle)->latest(),
        ] + $this->upload->formContext($vehicle, AttachmentOwner::Fuel, $entry?->id), $status);
    }
}
