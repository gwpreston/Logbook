<?php

declare(strict_types=1);

namespace Logbook\Action\Backup;

use Logbook\Service\Backup\BackupService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /settings/backup/download — the whole dataset (database + uploads) as
 * one ZIP (spec.md §7.13).
 */
final readonly class DownloadBackupAction
{
    public function __construct(
        private BackupService $backups,
        private StreamFactoryInterface $streams,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!BackupService::isAvailable()) {
            throw new HttpNotFoundException($request);
        }

        // Built in a temporary file, then handed over as a php://temp stream
        // (which spills to disk and cleans up after itself).
        $file = tempnam(sys_get_temp_dir(), 'logbook-backup-');
        if ($file === false) {
            throw new RuntimeException('Cannot create a temporary file for the backup.');
        }
        try {
            $this->backups->create($file);
            $body = $this->streams->createStream();
            $source = $this->streams->createStreamFromFile($file, 'rb');
            while (!$source->eof()) {
                $body->write($source->read(1024 * 1024));
            }
            $source->close();
            $body->rewind();
        } finally {
            @unlink($file);
        }

        return $response
            ->withBody($body)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $this->backups->filename()))
            ->withHeader('Content-Length', (string) $body->getSize())
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
