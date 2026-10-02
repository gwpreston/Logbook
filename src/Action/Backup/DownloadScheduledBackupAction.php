<?php

declare(strict_types=1);

namespace Logbook\Action\Backup;

use Logbook\Service\Jobs\ScheduledBackups;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /settings/backup/files/{name} — one scheduled backup from
 * BACKUP_PATH (spec.md §7.13, §7.30). Only a `logbook-scheduled-…zip`
 * name is served; anything else is 404.
 */
final readonly class DownloadScheduledBackupAction
{
    public function __construct(
        private ScheduledBackups $files,
        private StreamFactoryInterface $streams,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $name = $args['name'] ?? '';
        $path = $this->files->path($name) ?? throw new HttpNotFoundException($request);
        $body = $this->streams->createStreamFromFile($path, 'rb');

        return $response
            ->withBody($body)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $name))
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
