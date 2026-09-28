<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\Fuel\FuelEntryForm;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/fuel/{entry}/edit — edit a fill-up; its odometer
 * reading moves with it and every derived figure is recomputed on the next
 * read. A file chosen here is added to its attachments.
 */
final readonly class EditFuelEntryAction
{
    public function __construct(
        private VehicleService $vehicles,
        private FuelService $fuel,
        private FuelFormPage $page,
        private FuelSavedFlash $flash,
        private AttachmentUpload $upload,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $entry = FuelRoute::entry($this->fuel, $vehicle, $request, $args);
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        if ($request->getMethod() !== 'POST') {
            $values = FuelEntryForm::values($entry, $user->preferences);

            return $this->page->render($request, $response, $vehicle, $currency, $values, $entry);
        }

        $data = FuelEntryForm::parse(RequestContext::form($request), $user->preferences, $currency);
        $files = $this->upload->fromRequest($request);
        $errors = $this->upload->errors($data, $files);
        if ($errors !== null || $data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $currency, $values, $entry, $errors, 422);
        }

        $updated = $this->fuel->update($vehicle, $entry, $data, $files);
        $this->flash->queue(RequestContext::session($request), $vehicle, $updated, 'fuel.updated');

        return $this->redirect->backOr($request, 'fuel.index', ['id' => (string) $vehicle->id]);
    }
}
