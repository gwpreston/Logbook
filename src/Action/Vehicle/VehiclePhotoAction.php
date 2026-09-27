<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Service\Vehicle\VehicleService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /vehicles/{id}/photo — the authenticated handler that serves photos
 * from UPLOAD_PATH (never a public path). Photo URLs carry a version, so the
 * browser may cache them privately for good.
 */
final readonly class VehiclePhotoAction
{
    public function __construct(
        private VehicleService $vehicles,
        private StreamFactoryInterface $streams,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $path = $this->vehicles->photoFile($vehicle);
        if ($path === null || $vehicle->photoMime === null) {
            throw new HttpNotFoundException($request);
        }

        $etag = '"' . $vehicle->photoVersion() . '"';
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
            ->withHeader('Content-Type', $vehicle->photoMime)
            ->withHeader('Content-Length', (string) ($size === false ? 0 : $size))
            ->withHeader('Content-Disposition', 'inline')
            ->withBody($this->streams->createStreamFromFile($path, 'rb'));
    }
}
