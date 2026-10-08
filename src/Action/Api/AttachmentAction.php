<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Action\Attachment\AttachmentFile;
use Logbook\Action\Attachment\TripFileGuard;
use Logbook\Service\Api\ApiAttachments;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET and DELETE /api/v1/attachments/{attachment} (spec.md §7.20
 * *Attachments*): the file through the pages' handler (AttachmentFile, so
 * an incident photo follows #104), or deleted as the page's delete link
 * does (`204`). The path names no vehicle: the attachment's own is checked
 * for the key's user, and a trip's file is only for those who may see the
 * trip (TripFileGuard); anything else is 404.
 */
final readonly class AttachmentAction
{
    public function __construct(
        private ApiAttachments $attachments,
        private TripFileGuard $tripFiles,
        private AttachmentFile $file,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        ['vehicle' => $vehicle, 'attachment' => $attachment] = $this->attachments->find($user, (int) ($args['attachment'] ?? 0));
        if (!$this->tripFiles->mayUse($user, $vehicle, $attachment)) {
            throw ApiProblem::notFound('There is no such attachment.');
        }

        if ($request->getMethod() === 'DELETE') {
            $this->attachments->delete($user, $vehicle, $attachment);

            return $response->withStatus(204);
        }

        return $this->file->send($request, $response, $user, $vehicle, $attachment);
    }
}
