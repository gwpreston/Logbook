<?php

declare(strict_types=1);

namespace Logbook\Service\Attachment;

use Countable;

/**
 * The files chosen in one save (spec.md §7.12), each already checked. They
 * are stored all or nothing: one rejected file fails the whole save.
 */
final readonly class PendingUploads implements Countable
{
    /**
     * @param list<PendingUpload> $files
     */
    public function __construct(public array $files = [])
    {
    }

    public function count(): int
    {
        return count($this->files);
    }

    public function isEmpty(): bool
    {
        return $this->files === [];
    }

    /**
     * The first file that failed its check, if any.
     */
    public function firstRejected(): ?PendingUpload
    {
        foreach ($this->files as $file) {
            if (!$file->check->isValid()) {
                return $file;
            }
        }

        return null;
    }
}
