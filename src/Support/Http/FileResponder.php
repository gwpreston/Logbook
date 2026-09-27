<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Streams a stored upload (a vehicle photo or an attachment) from
 * UPLOAD_PATH. Only Actions behind the auth guard call it, after resolving
 * the file through the signed-in owner's vehicle, so this is the one way an
 * uploaded file ever leaves the server.
 *
 * Responses are hardened against anything a file could smuggle in: the type
 * is the one detected at upload, never sniffed; the file is sandboxed; and
 * the browser may only cache it privately. Stored names are random and never
 * reused, so the version (ETag) never changes for a URL.
 */
final readonly class FileResponder
{
    public function __construct(private StreamFactoryInterface $streams)
    {
    }

    /**
     * @param string $path absolute path of an existing file
     * @param string $version stable validator for the file (becomes the ETag)
     * @param string|null $downloadName offer the file as a download under this
     *                                  name; null shows it inline
     */
    public function send(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $path,
        string $mime,
        string $version,
        ?string $downloadName = null,
    ): ResponseInterface {
        $etag = '"' . $version . '"';
        $response = $response
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', 'private, max-age=31536000, immutable')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox");

        if ($request->getHeaderLine('If-None-Match') === $etag) {
            return $response->withStatus(304);
        }

        $size = filesize($path);

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) ($size === false ? 0 : $size))
            ->withHeader('Content-Disposition', $downloadName === null ? 'inline' : self::attachment($downloadName))
            ->withBody($this->streams->createStreamFromFile($path, 'rb'));
    }

    /**
     * RFC 6266 disposition with an ASCII fallback and the UTF-8 name.
     */
    private static function attachment(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\%]/', '_', $name) ?? 'download';

        return sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($name));
    }
}
