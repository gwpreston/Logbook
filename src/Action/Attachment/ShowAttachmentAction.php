<?php

declare(strict_types=1);

namespace Logbook\Action\Attachment;

use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Repository\IncidentRepository;
use Logbook\Service\Incident\IncidentAccess;
use Logbook\Support\Storage\ImageCleaner;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Support\Http\FileResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /vehicles/{id}/attachments/{attachment} — serves an attachment to its
 * signed-in owner through the shared FileResponder (the same handler as
 * vehicle photos). Images open in the browser; PDFs, and anything with
 * ?download=1, download under their original name (browsers will not render
 * a PDF inside the sandbox every file is served with).
 */
final readonly class ShowAttachmentAction
{
    public function __construct(
        private AttachmentService $attachments,
        private TripFileGuard $tripFiles,
        private FileResponder $files,
        private IncidentRepository $incidents,
        private IncidentAccess $incidentAccess,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $attachment = AttachmentRoute::attachment($this->attachments, $vehicle, $request, $args);
        $this->tripFiles->allow($request, $attachment);
        $path = $this->attachments->file($attachment);
        if ($path === null) {
            throw new HttpNotFoundException($request);
        }

        $download = !$attachment->isImage() || ($request->getQueryParams()['download'] ?? '') === '1';

        // An incident photo is kept as taken (spec.md §7.12): who may not see
        // the incident's location gets it upright and stripped (decided 2026-10-01, #104).
        if ($attachment->ownerType === AttachmentOwner::Incident && $attachment->isImage()) {
            $incident = $this->incidents->find($vehicle->id, $attachment->ownerId);
            $user = RequestContext::requireUser($request);
            if ($incident === null || !$this->incidentAccess->seesDetails($user, $vehicle, $incident)) {
                $bytes = ImageCleaner::cleanedBytes($path, $attachment->mime) ?? throw new HttpNotFoundException($request);

                return $this->files->sendBytes(
                    $request,
                    $response,
                    $bytes,
                    $attachment->mime,
                    $attachment->version() . '-clean',
                    $download ? $attachment->filename : null,
                );
            }
        }

        return $this->files->send(
            $request,
            $response,
            $path,
            $attachment->mime,
            $attachment->version(),
            $download ? $attachment->filename : null,
        );
    }
}
