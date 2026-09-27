<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Service\Fuel\FuelEntryForm;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/fuel/new — log a fill-up (or charge). Also writes
 * the matching odometer reading, and stores a receipt if one was attached.
 */
final readonly class CreateFuelEntryAction
{
    public function __construct(
        private VehicleService $vehicles,
        private FuelService $fuel,
        private FuelFormPage $page,
        private FuelSavedFlash $flash,
        private AttachmentUpload $upload,
        private Redirector $redirect,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        if ($request->getMethod() !== 'POST') {
            $defaults = FuelEntryForm::defaults($vehicle, $this->clock->now(), $user->preferences);

            return $this->page->render($request, $response, $vehicle, $currency, $defaults);
        }

        $data = FuelEntryForm::parse(RequestContext::form($request), $user->preferences, $currency);
        $file = $this->upload->fromRequest($request);
        $errors = $this->upload->errors($data, $file);
        if ($errors !== null || $data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $currency, $values, null, $errors, 422);
        }

        $entry = $this->fuel->create($vehicle, $data);
        $this->upload->store($vehicle, AttachmentOwner::Fuel, $entry->id, $file);
        $this->flash->queue(RequestContext::session($request), $vehicle, $entry, 'fuel.created');

        return $this->redirect->toRoute('fuel.index', ['id' => (string) $vehicle->id]);
    }
}
