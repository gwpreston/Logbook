<?php

declare(strict_types=1);

namespace Logbook\Action\Backup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /settings/backup — download a backup, or upload one to restore.
 */
final readonly class BackupPageAction
{
    public function __construct(private BackupPage $page)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page->render($request, $response);
    }
}
