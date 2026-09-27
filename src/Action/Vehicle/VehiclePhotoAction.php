<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\FileResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /vehicles/{id}/photo — serves the vehicle's photo from UPLOAD_PATH
 * (never a public path) through the shared authenticated FileResponder.
 * Photo URLs carry a version, so the browser may cache them privately for good.
 */
final readonly class VehiclePhotoAction
{
    public function __construct(
        private VehicleService $vehicles,
        private FileResponder $files,
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

        return $this->files->send($request, $response, $path, $vehicle->photoMime, $vehicle->photoVersion());
    }
}
