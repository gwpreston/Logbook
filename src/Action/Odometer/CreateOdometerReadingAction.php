<?php

declare(strict_types=1);

namespace Logbook\Action\Odometer;

use Logbook\Action\Ask\DraftPrefill;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/odometer/new — add a manual reading. Implausible
 * readings are saved with a warning, never refused.
 */
final readonly class CreateOdometerReadingAction
{
    public function __construct(
        private DraftPrefill $prefill,
        private OdometerService $odometer,
        private OdometerFormPage $page,
        private AttachmentUpload $upload,
        private OdometerWarningFlash $warnings,
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
        $preferences = RequestContext::requireUser($request)->preferences;

        if ($request->getMethod() !== 'POST') {
            $defaults = OdometerReadingForm::defaults($this->clock->now(), $preferences);
            $defaults = $this->prefill->values($request, DraftKind::Odometer, $vehicle->id, $defaults);

            return $this->page->render($request, $response, $vehicle, $defaults);
        }

        $data = OdometerReadingForm::parse(RequestContext::form($request), $preferences);
        $files = $this->upload->fromRequest($request);
        $errors = $this->upload->errors($data, $files);
        if ($errors !== null || $data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $values, null, $errors, 422);
        }

        $reading = $this->odometer->create($vehicle, $data, $files);
        $this->prefill->saved($request);
        $session = RequestContext::session($request);
        $session->flash('success', 'odometer.created');
        $this->warnings->queue($session, $this->odometer->warningFor($vehicle, $reading->id));

        return $this->redirect->backOr($request, 'odometer.index', ['id' => (string) $vehicle->id]);
    }
}
