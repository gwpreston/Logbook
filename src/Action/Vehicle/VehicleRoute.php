<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Service\Vehicle\VehicleForm;
use Logbook\Support\Session\Session;
use Logbook\Support\Storage\FileUpload;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Shared request plumbing for the vehicle forms. The vehicle itself comes
 * from RequestContext::vehicle() (VehicleAccessMiddleware).
 */
final class VehicleRoute
{
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
