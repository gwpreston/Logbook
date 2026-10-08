<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiWriter;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Api\ValidationProblem;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\UploadKind;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST and DELETE /api/v1/vehicles/{id}/photo (spec.md §7.20 *Attachments*,
 * #300): replace the vehicle's photo with the image in the multipart field
 * `file`, checked as the edit form checks it, or remove it; `204` either
 * way. `Manage`, as the edit form; an archived vehicle is 409. `GET` is the
 * pages' VehiclePhotoAction.
 */
final readonly class VehiclePhotoWriteAction
{
    public const string FIELD = 'file';

    public function __construct(
        private VehicleService $vehicles,
        private ValidationProblem $validation,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $vehicle = RequestContext::vehicle($request);
        ApiWriter::assertActive($vehicle);

        if ($request->getMethod() === 'DELETE') {
            if ($vehicle->hasPhoto()) {
                $this->vehicles->removePhoto($user, $vehicle);
            }

            return $response->withStatus(204);
        }

        $file = $request->getUploadedFiles()[self::FIELD] ?? null;
        $errors = new ValidationErrors();
        if (!$file instanceof UploadedFileInterface && EntryAttachmentsAction::bodyDropped($request)) {
            $errors->add(self::FIELD, 'upload.too_large', ['max' => $this->vehicles->maxPhotoMegabytes()]);
            throw $this->validation->of($errors);
        }
        if (!$file instanceof UploadedFileInterface || !FileUpload::wasProvided($file)) {
            $errors->add(self::FIELD, 'validation.required');
            throw $this->validation->of($errors);
        }
        $checked = FileUpload::check($file, $this->vehicles->maxPhotoBytes(), UploadKind::Image);
        if ($checked->error !== null) {
            $errors->add(self::FIELD, $checked->error, ['max' => $this->vehicles->maxPhotoMegabytes()]);
            throw $this->validation->of($errors);
        }
        $this->vehicles->replacePhoto($user, $vehicle, $file, $checked);

        return $response->withStatus(204);
    }
}
