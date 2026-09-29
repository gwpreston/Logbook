<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Vehicle\OwnershipFiles;
use Logbook\Service\Vehicle\PaperworkNeedsDate;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The vehicle form's purchase and sale paperwork (spec.md §7.1, §7.12): two
 * inputs of the shared attachment parser, sharing one limit per save.
 */
final readonly class VehiclePaperwork
{
    public const string PURCHASE_FIELD = 'purchase_attachments';
    public const string SALE_FIELD = 'sale_attachments';

    public function __construct(private AttachmentUpload $upload, private AttachmentService $attachments)
    {
    }

    public function fromRequest(ServerRequestInterface $request): OwnershipFiles
    {
        return new OwnershipFiles(
            $this->upload->fromRequest($request, self::PURCHASE_FIELD),
            $this->upload->fromRequest($request, self::SALE_FIELD),
        );
    }

    /**
     * The form's errors plus the files' (both inputs counted together).
     */
    public function errors(object $parsed, OwnershipFiles $files): ?ValidationErrors
    {
        return $this->upload->errorsFor($parsed, [
            self::PURCHASE_FIELD => $files->purchase,
            self::SALE_FIELD => $files->sale,
        ]);
    }

    public static function refusal(PaperworkNeedsDate $refused): ValidationErrors
    {
        $errors = new ValidationErrors();
        $errors->add($refused->field(), $refused->messageKey());

        return $errors;
    }

    /**
     * Template variables for the two inputs: the files already attached to
     * the purchase and the sale (none for a new vehicle) and the shared
     * limit.
     *
     * @return array{purchase_files: list<Attachment>, sale_files: list<Attachment>, max_upload_files: int}
     */
    public function formContext(?Vehicle $vehicle): array
    {
        return [
            'purchase_files' => $this->files($vehicle, AttachmentOwner::Purchase),
            'sale_files' => $this->files($vehicle, AttachmentOwner::Sale),
            'max_upload_files' => $this->attachments->maxFiles(),
        ];
    }

    /**
     * @return list<Attachment>
     */
    private function files(?Vehicle $vehicle, AttachmentOwner $side): array
    {
        return $vehicle === null ? [] : $this->attachments->forOwner($vehicle, $side, $vehicle->id);
    }
}
