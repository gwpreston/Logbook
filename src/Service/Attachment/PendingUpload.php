<?php

declare(strict_types=1);

namespace Logbook\Service\Attachment;

use Logbook\Support\Storage\FileUpload;
use Psr\Http\Message\UploadedFileInterface;

/**
 * One file chosen in an entry form's attachment input, with its check result.
 */
final readonly class PendingUpload
{
    private const int MAX_NAME_LENGTH = 80;

    public function __construct(
        public UploadedFileInterface $file,
        public FileUpload $check,
    ) {
    }

    /**
     * The name the browser sent, safe to show in a message ("receipt.heic").
     */
    public function name(): string
    {
        $name = basename(str_replace('\\', '/', (string) $this->file->getClientFilename()));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', mb_check_encoding($name, 'UTF-8') ? $name : '');

        return $name === '' ? '?' : mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }
}
