<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Fuel\FuelPicker;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
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
        private FuelService $fuel,
        private AttachmentUpload $upload,
        private ClockInterface $clock,
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
        $picker = FuelPicker::groups(
            $vehicle,
            $this->fuel->entries($vehicle),
            RequestContext::requireUser($request)->preferences->locale,
            $this->clock->now(),
            $values['fuel'] ?? '',
        );

        // The economy check of the segment this fill-up closes, shown above the form.
        $check = $entry === null ? null : $this->fuel->checks($this->fuel->history($vehicle))->for($entry->id);

        return $this->view->render($request, $response, 'fuel/form.twig', [
            'vehicle' => $vehicle,
            'entry' => $entry,
            'check' => $check !== null && ($check->isFlagged() || $check->isConfirmedFlag()) ? $check : null,
            'currency' => $currency,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'fuel_groups' => $picker,
            'latest' => $this->odometer->history($vehicle)->latest(),
        ] + $this->upload->formContext($vehicle, AttachmentOwner::Fuel, $entry?->id), $status);
    }
}
