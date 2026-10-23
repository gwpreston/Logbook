<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\EntryGuard;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Part\WarrantyStart;
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
 * GET/POST /vehicles/{id}/parts/{part}/edit (spec.md §7.39): `Manage`, or
 * `Log` for a part the user added. Its fittings change through *Fit*,
 * *Remove* and the records that made them.
 */
final readonly class EditPartAction
{
    public function __construct(
        private PartService $parts,
        private PartFormPage $page,
        private AttachmentUpload $upload,
        private Redirector $redirect,
        private EntryGuard $guard,
        private CostInput $costs,
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
        $part = PartRoute::part($this->parts, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $part->createdBy);
        $refused = PartRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        if ($request->getMethod() !== 'POST') {
            // #487: with no start stored, *Purchase date* chosen as on a new part's form (#482), so a
            // warranty added here is stored as one added new. The API's PATCH keeps what POST stores.
            $values = PartForm::values($part, $user->preferences);
            $values['warranty_from'] = $values['warranty_from'] !== '' ? $values['warranty_from'] : WarrantyStart::Purchase->value;

            return $this->page->render($request, $response, $vehicle, $values, $part);
        }

        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $data = PartForm::parse(
            $this->costs->guard($request, $vehicle, RequestContext::form($request), PartForm::values($part, $user->preferences)),
            $user->preferences,
            $today,
            $part,
        );
        $files = $this->upload->fromRequest($request, owner: AttachmentOwner::Part);
        $errors = $this->upload->errors($data, $files);
        if ($errors !== null || $data instanceof ValidationErrors) {
            return $this->page->render($request, $response, $vehicle, RequestContext::formValues($request), $part, $errors, 422);
        }
        $saved = $this->parts->update($vehicle, $part, $data, $files);
        RequestContext::session($request)->flash('success', 'part.updated', ['name' => $saved->label()]);

        return $this->redirect->backOr($request, 'parts.show', ['id' => (string) $vehicle->id, 'part' => (string) $part->id]);
    }
}
