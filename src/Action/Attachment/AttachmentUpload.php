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
 * document, expense and manual reading forms, and of the vehicle form's
 * purchase and sale paperwork (`purchase_attachments[]`,
 * `sale_attachments[]`; spec.md §7.12). Every chosen file is checked with
 * the rest of the form: too many files, or one rejected file, fails the
 * whole submission and nothing is saved. The entry's service stores them
 * with the entry.
 */
final readonly class AttachmentUpload
{
    public const string FIELD = 'attachments';

    public function __construct(private AttachmentService $attachments)
    {
    }

    /**
     * The files chosen in the input named $field, each checked; empty when
     * the input was left empty.
     *
     * @param AttachmentOwner|null $owner what the files will belong to (spec.md §7.12)
     */
    public function fromRequest(
        ServerRequestInterface $request,
        string $field = self::FIELD,
        ?AttachmentOwner $owner = null,
    ): PendingUploads {
        $given = $request->getUploadedFiles()[$field] ?? [];
        $pending = [];
        foreach (is_array($given) ? $given : [$given] as $file) {
            if ($file instanceof UploadedFileInterface && FileUpload::wasProvided($file)) {
                $pending[] = new PendingUpload($file, $this->attachments->check($file, $owner));
            }
        }

        return new PendingUploads($pending);
    }

    /**
     * The form's errors plus the files', or null when both are fine. The
     * message names the rejected file.
     */
    public function errors(object $parsed, PendingUploads $uploads, string $field = self::FIELD): ?ValidationErrors
    {
        return $this->errorsFor($parsed, [$field => $uploads]);
    }

    /**
     * The same for a form with several inputs, keyed by field name. They
     * share one limit: PHP's max_file_uploads counts every file in the
     * request, whichever input it came from.
     *
     * @param array<string, PendingUploads> $inputs
     */
    public function errorsFor(object $parsed, array $inputs): ?ValidationErrors
    {
        $errors = $parsed instanceof ValidationErrors ? $parsed : null;
        $max = $this->attachments->maxFiles();
        $total = array_sum(array_map(count(...), $inputs));

        foreach ($inputs as $field => $uploads) {
            $rejected = $uploads->firstRejected();
            if ($total > $max) {
                if (!$uploads->isEmpty()) {
                    $errors ??= new ValidationErrors();
                    $errors->add($field, 'upload.too_many', ['max' => $max]);
                }
            } elseif ($rejected !== null && $rejected->check->error !== null) {
                $errors ??= new ValidationErrors();
                $errors->add($field, 'upload.file.' . substr($rejected->check->error, strlen('upload.')), [
                    'name' => $rejected->name(),
                    'max' => $this->attachments->maxMegabytes(),
                ]);
            }
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
