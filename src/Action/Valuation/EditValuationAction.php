<?php

declare(strict_types=1);

namespace Logbook\Action\Valuation;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\Valuation\ValuationForm;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/valuations/{entry}/edit — edit a valuation in place.
 */
final readonly class EditValuationAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ValuationService $valuations,
        private ValuationFormPage $page,
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
        $valuation = ValuationRoute::valuation($this->valuations, $vehicle, $request, $args);
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response, $vehicle, $currency, ValuationForm::values($valuation), $valuation);
        }

        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $data = ValuationForm::parse(RequestContext::form($request), $user->preferences, $today, $vehicle);
        $files = $this->upload->fromRequest($request);
        $errors = $this->upload->errors($data, $files);
        if ($errors !== null || $data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $currency, $values, $valuation, $errors, 422);
        }

        $this->valuations->update($vehicle, $valuation, $data, $files);
        RequestContext::session($request)->flash('success', 'valuation.updated');

        return $this->redirect->backOr($request, 'valuations.index', ['id' => (string) $vehicle->id]);
    }
}
