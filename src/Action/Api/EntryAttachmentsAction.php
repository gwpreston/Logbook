<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiAttachments;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * GET and POST /api/v1/vehicles/{id}/{list}/{entry}/attachments (and
 * `…/purchase/attachments`, `…/sale/attachments`) — an entry's files, and
 * one more as `multipart/form-data` in the field `file` (spec.md §7.20
 * *Attachments*, #286): `201` with the attachment. The route names the
 * owner in its `list` argument.
 */
final readonly class EntryAttachmentsAction
{
    public function __construct(
        private ApiAttachments $attachments,
        private ApiResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $list = $args['list'] ?? '';
        if (!array_key_exists($list, ApiAttachments::OWNERS)) {
            throw new \LogicException(sprintf('The route names no attachment owner ("%s").', $list));
        }
        $user = RequestContext::requireUser($request);
        $vehicle = RequestContext::vehicle($request);
        $entry = isset($args['entry']) ? (int) $args['entry'] : null;

        if ($request->getMethod() !== 'POST') {
            return $this->responder->json(['items' => $this->attachments->list($list, $user, $vehicle, $entry)]);
        }
        $file = $request->getUploadedFiles()[ApiAttachments::FIELD] ?? null;
        $file = $file instanceof UploadedFileInterface ? $file : null;

        return $this->responder->json(
            $this->attachments->upload($list, $user, $vehicle, $entry, $file, $file === null && self::bodyDropped($request)),
            201,
        );
    }

    /**
     * A multipart body PHP threw away for being over `post_max_size`: it
     * arrives with no files and no fields, so say it was too large rather
     * than that the file is missing.
     */
    public static function bodyDropped(ServerRequestInterface $request): bool
    {
        return str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'multipart/form-data')
            && (int) $request->getHeaderLine('Content-Length') > 0
            && $request->getUploadedFiles() === []
            && in_array($request->getParsedBody(), [null, []], true);
    }
}
