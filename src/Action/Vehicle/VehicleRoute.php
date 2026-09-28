<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Service\Vehicle\VehicleForm;
use Logbook\Service\Vehicle\VehicleNotFound;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Session\Session;
use Logbook\Support\Storage\FileUpload;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared request plumbing for the /vehicles/{id} routes.
 */
final class VehicleRoute
{
    /**
     * The signed-in owner's vehicle named by the route, or a 404 (also for
     * someone else's vehicle, so ids reveal nothing).
     *
     * @param array<string, string> $args
     */
    public static function vehicle(VehicleService $vehicles, ServerRequestInterface $request, array $args): Vehicle
    {
        try {
            return $vehicles->get(RequestContext::requireUser($request), (int) ($args['id'] ?? 0));
        } catch (VehicleNotFound) {
            throw new HttpNotFoundException($request);
        }
    }

    /**
     * The chosen photo file, if the form's file input was used.
     */
    public static function photo(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $file = $request->getUploadedFiles()['photo'] ?? null;

        return $file instanceof UploadedFileInterface && FileUpload::wasProvided($file) ? $file : null;
    }

    /**
     * After a save: "check both" when the model year is after the
     * registration year. The vehicle is saved either way.
     */
    public static function flashModelYearWarning(Session $session, VehicleData $data): void
    {
        $warning = VehicleForm::modelYearWarning($data);
        if ($warning !== null) {
            $session->flash('warning', 'vehicle.model_year_warning', $warning);
        }
    }
}
