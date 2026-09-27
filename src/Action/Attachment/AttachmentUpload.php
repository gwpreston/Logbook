<?php

declare(strict_types=1);

namespace Logbook\Action\Attachment;

use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The optional "attach a file" input shared by the fill-up, maintenance and
 * compliance forms. The file is checked with the rest of the form: a
 * rejected file fails the whole submission (nothing is saved), a valid one
 * is stored against the entry once the entry is saved.
 */
final readonly class AttachmentUpload
{
    public const string FIELD = 'attachment';

    public function __construct(private AttachmentService $attachments)
    {
    }

    /**
     * The chosen file, checked; null when the input was left empty.
     */
    public function fromRequest(ServerRequestInterface $request): ?PendingAttachment
    {
        $file = $request->getUploadedFiles()[self::FIELD] ?? null;
        if (!$file instanceof UploadedFileInterface || !FileUpload::wasProvided($file)) {
            return null;
        }

        return new PendingAttachment($file, $this->attachments->check($file));
    }

    /**
     * The form's errors plus the file's, or null when both are fine.
     */
    public function errors(object $parsed, ?PendingAttachment $upload): ?ValidationErrors
    {
        $errors = $parsed instanceof ValidationErrors ? $parsed : null;
        if ($upload !== null && $upload->check->error !== null) {
            $errors ??= new ValidationErrors();
            $errors->add(self::FIELD, $upload->check->error, ['max' => $this->attachments->maxMegabytes()]);
        }

        return $errors;
    }

    public function store(Vehicle $vehicle, AttachmentOwner $type, int $ownerId, ?PendingAttachment $upload): void
    {
        if ($upload !== null) {
            $this->attachments->attach($vehicle, $type, $ownerId, $upload->file, $upload->check);
        }
    }

    /**
     * Template variables for the attachments card of a form.
     *
     * @return array{attachments: list<Attachment>, max_upload_mb: int}
     */
    public function formContext(Vehicle $vehicle, AttachmentOwner $type, ?int $ownerId): array
    {
        return [
            'attachments' => $ownerId === null ? [] : $this->attachments->forOwner($vehicle, $type, $ownerId),
            'max_upload_mb' => $this->attachments->maxMegabytes(),
        ];
    }
}
