<?php

declare(strict_types=1);

namespace Logbook\Action\Attachment;

use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUpload;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The one parser for the shared attachment input (`attachments[]`,
 * templates/macros/attachments.twig) of the fill-up, service record,
 * document, expense and manual reading forms (spec.md §7.12). Every chosen
 * file is checked with the rest of the form: too many files, or one
 * rejected file, fails the whole submission and nothing is saved. The
 * entry's service stores them with the entry.
 */
final readonly class AttachmentUpload
{
    public const string FIELD = 'attachments';

    public function __construct(private AttachmentService $attachments)
    {
    }

    /**
     * The chosen files, each checked; empty when the input was left empty.
     */
    public function fromRequest(ServerRequestInterface $request): PendingUploads
    {
        $given = $request->getUploadedFiles()[self::FIELD] ?? [];
        $pending = [];
        foreach (is_array($given) ? $given : [$given] as $file) {
            if ($file instanceof UploadedFileInterface && FileUpload::wasProvided($file)) {
                $pending[] = new PendingUpload($file, $this->attachments->check($file));
            }
        }

        return new PendingUploads($pending);
    }

    /**
     * The form's errors plus the files', or null when both are fine. The
     * message names the rejected file.
     */
    public function errors(object $parsed, PendingUploads $uploads): ?ValidationErrors
    {
        $errors = $parsed instanceof ValidationErrors ? $parsed : null;
        $max = $this->attachments->maxFiles();
        $rejected = $uploads->firstRejected();

        if (count($uploads) > $max) {
            $errors ??= new ValidationErrors();
            $errors->add(self::FIELD, 'upload.too_many', ['max' => $max]);
        } elseif ($rejected !== null && $rejected->check->error !== null) {
            $errors ??= new ValidationErrors();
            $errors->add(self::FIELD, 'upload.file.' . substr($rejected->check->error, strlen('upload.')), [
                'name' => $rejected->name(),
                'max' => $this->attachments->maxMegabytes(),
            ]);
        }

        return $errors;
    }

    /**
     * Template variables for the attachments card of a form.
     *
     * @return array{attachments: list<Attachment>, max_upload_mb: int, max_upload_files: int}
     */
    public function formContext(Vehicle $vehicle, AttachmentOwner $type, ?int $ownerId): array
    {
        return [
            'attachments' => $ownerId === null ? [] : $this->attachments->forOwner($vehicle, $type, $ownerId),
            'max_upload_mb' => $this->attachments->maxMegabytes(),
            'max_upload_files' => $this->attachments->maxFiles(),
        ];
    }
}
