<?php

declare(strict_types=1);

namespace Logbook\Action\Attachment;

use Logbook\Support\Storage\FileUpload;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A file chosen in a form's attachment input, with its check result.
 */
final readonly class PendingAttachment
{
    public function __construct(
        public UploadedFileInterface $file,
        public FileUpload $check,
    ) {
    }
}
