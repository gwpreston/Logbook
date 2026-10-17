<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Service\Part\PartForm;
use Logbook\Service\Part\PartService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET/POST /vehicles/{id}/parts/new — add a part on its own: a spare bought
 * ahead (stored), or one *Already on the car* (fitted without a record,
 * #418) when its fitted date is given.
 */
final readonly class CreatePartAction
{
    public function __construct(
        private PartService $parts,
        private CostInput $costs,
        private PartFormPage $page,
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
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $refused = PartRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        if ($request->getMethod() !== 'POST') {
            // #482: *Purchase date* chosen, so a new part's form always shows where its warranty starts.
            return $this->page->render($request, $response, $vehicle, ['condition' => 'new', 'warranty_from' => 'purchase']);
        }

        $input = $this->costs->guard($request, $vehicle, RequestContext::form($request));
        $data = PartForm::parse($input, $user->preferences, $today);
        $onCar = ($input['on_car'] ?? '') === '1';
        $fit = $onCar ? PartForm::parseFit($input, $user->preferences, $today) : null;
        $files = $this->upload->fromRequest($request, owner: AttachmentOwner::Part);
        $errors = $this->upload->errors($data, $files);
        if ($fit instanceof ValidationErrors) {
            $errors = ($errors ?? ($data instanceof ValidationErrors ? $data : new ValidationErrors()))->merge($fit);
        }
        if ($errors !== null || $data instanceof ValidationErrors || $fit instanceof ValidationErrors) {
            return $this->page->render(
                $request,
                $response,
                $vehicle,
                RequestContext::formValues($request),
                null,
                $errors ?? ($data instanceof ValidationErrors ? $data : null),
                422,
            );
        }

        $part = $this->parts->create($vehicle, $data, $zone, $files, $fit['on'] ?? null, $fit['km'] ?? null);
        RequestContext::session($request)->flash('success', 'part.created', ['name' => $part->label()]);

        return $this->redirect->backOr($request, 'parts.show', ['id' => (string) $vehicle->id, 'part' => (string) $part->id]);
    }
}
