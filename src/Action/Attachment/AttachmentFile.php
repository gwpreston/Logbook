<?php

declare(strict_types=1);

namespace Logbook\Action\Attachment;

use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\IncidentRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Incident\IncidentAccess;
use Logbook\Support\Http\FileResponder;
use Logbook\Support\Storage\ImageCleaner;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Sends an attachment's file through the shared FileResponder: the one
 * handler for the pages' `/vehicles/{id}/attachments/{attachment}` and the
 * API's `/attachments/{id}` (spec.md §7.12, §7.20 *Attachments*). Images
 * open in the browser; PDFs, and anything with ?download=1, download under
 * their original name (browsers will not render a PDF inside the sandbox
 * every file is served with). The caller has checked access to the vehicle
 * and AttachmentGuard.
 */
final readonly class AttachmentFile
{
    public function __construct(
        private AttachmentService $attachments,
        private FileResponder $files,
        private IncidentRepository $incidents,
        private IncidentAccess $incidentAccess,
    ) {
    }

    public function send(
        ServerRequestInterface $request,
        ResponseInterface $response,
        User $user,
        Vehicle $vehicle,
        Attachment $attachment,
    ): ResponseInterface {
        $path = $this->attachments->file($attachment);
        if ($path === null) {
            throw new HttpNotFoundException($request);
        }

        $download = !$attachment->isImage() || ($request->getQueryParams()['download'] ?? '') === '1';

        // An incident photo is kept as taken (spec.md §7.12): who may not see
        // the incident's location gets it upright and stripped (decided 2026-10-01, #104).
        if ($attachment->ownerType === AttachmentOwner::Incident && $attachment->isImage()) {
            $incident = $this->incidents->find($vehicle->id, $attachment->ownerId);
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
