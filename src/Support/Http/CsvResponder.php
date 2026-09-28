<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Logbook\Support\Csv\CsvTable;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends a CsvTable as a download: UTF-8 text/csv, never cached by shared
 * caches, never sniffed as something else.
 */
final class CsvResponder
{
    public static function send(ResponseInterface $response, CsvTable $table): ResponseInterface
    {
        $response->getBody()->write($table->toCsv());

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $table->filename))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
