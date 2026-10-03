<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Action\Ask\DraftPrefill;
use Logbook\Action\Scan\ScanPrefill;
use Logbook\Domain\Ai\Scan\ScanTarget;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Action\Attachment\AttachmentUpload;
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
        private DraftPrefill $prefill,
        private ScanPrefill $scan,
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
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        if ($request->getMethod() !== 'POST') {
            $entries = $this->fuel->entries($vehicle);
            $defaults = FuelEntryForm::defaults($vehicle, $this->clock->now(), $user->preferences, $entries);
            $defaults = $this->prefill->values($request, DraftKind::Fuel, $vehicle->id, $defaults);
            $defaults = $this->scan->values($request, ScanTarget::Fuel, $vehicle, $defaults);

            return $this->page->render($request, $response, $vehicle, $currency, $defaults);
        }

        $data = FuelEntryForm::parse(RequestContext::form($request), $user->preferences, $currency);
        $files = $this->scan->files($request, $this->upload->fromRequest($request));
        $errors = $this->upload->errors($data, $files);
        if ($errors !== null || $data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $currency, $values, null, $errors, 422);
        }

        [$entry, $claimed] = $this->scan->save(
            $request,
            $files,
            fn (PendingUploads $files): FuelEntry => $this->fuel->create($vehicle, $data, $files),
        );
        $this->prefill->saved($request);
        $this->flash->queue(RequestContext::session($request), $vehicle, $entry, 'fuel.created', RequestContext::requireUser($request));

        $done = $this->redirect->backOr($request, 'fuel.index', ['id' => (string) $vehicle->id]);

        return $this->scan->after($request, $claimed, $vehicle, $entry->data->odometerKm, $done);
    }
}
