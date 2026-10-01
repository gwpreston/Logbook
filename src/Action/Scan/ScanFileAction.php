<?php

declare(strict_types=1);

namespace Logbook\Action\Scan;

use Logbook\Service\Ai\Scan\ScanReader;
use Logbook\Support\Http\FileResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /scan/{token}/file — a pending scan's file, for the thumbnail beside
 * the form (spec.md §7.27): to its own user only, through the same
 * responder as attachments. Gone once its entry is saved.
 */
final readonly class ScanFileAction
{
    public function __construct(
        private ScanGuard $guard,
        private ScanReader $reader,
        private FileResponder $files,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->guard->user($request);
        $upload = $this->guard->upload($request, $user, $args);
        $path = $this->reader->path($upload);
        if ($path === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->files->send(
            $request,
            $response,
            $path,
            $upload->mime,
            $upload->token,
            $upload->isImage() ? null : $upload->filename,
        );
    }
}
