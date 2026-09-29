<?php

declare(strict_types=1);

namespace Logbook\Action\Valuation;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the add/edit valuation form (shared by both Actions).
 */
final readonly class ValuationFormPage
{
    public function __construct(
        private View $view,
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
        ?VehicleValuation $valuation = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'valuations/form.twig', [
            'vehicle' => $vehicle,
            'valuation' => $valuation,
            'currency' => $currency,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
        ] + $this->upload->formContext($vehicle, AttachmentOwner::Valuation, $valuation?->id), $status);
    }
}
