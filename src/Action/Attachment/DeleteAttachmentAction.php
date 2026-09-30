<?php

declare(strict_types=1);

namespace Logbook\Action\Attachment;

use Logbook\Service\Attachment\AttachmentService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/attachments/{attachment}/delete — confirm (works
 * without JS), then delete the file and return to the entry it was on.
 */
final readonly class DeleteAttachmentAction
{
    public function __construct(
        private AttachmentService $attachments,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $attachment = AttachmentRoute::attachment($this->attachments, $vehicle, $request, $args);
        [$route, $params] = AttachmentRoute::ownerPage($attachment);
        $description = ['name' => $attachment->filename];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'attachment.delete_title',
                'body' => 'attachment.delete_body',
                'params' => $description,
                'action' => ['attachments.delete', ['id' => $vehicle->id, 'attachment' => $attachment->id]],
                'cancel' => [$route, $params],
            ]);
        }

        $this->attachments->delete($vehicle, $attachment);
        RequestContext::session($request)->flash('success', 'attachment.deleted', $description);

        return $this->redirect->toRoute($route, $params);
    }
}
