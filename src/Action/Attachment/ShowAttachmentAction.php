<?php

declare(strict_types=1);

namespace Logbook\Action\Attachment;

use Logbook\Service\Attachment\AttachmentService;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/attachments/{attachment} — serves an attachment to its
 * signed-in owner through AttachmentFile (the same handler as the API's
 * download, and the same responder as vehicle photos).
 */
final readonly class ShowAttachmentAction
{
    public function __construct(
        private AttachmentService $attachments,
        private TripFileGuard $tripFiles,
        private AttachmentFile $file,
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

        return $this->file->send($request, $response, RequestContext::requireUser($request), $vehicle, $attachment);
    }
}
