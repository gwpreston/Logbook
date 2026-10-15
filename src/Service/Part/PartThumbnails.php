<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Service\Attachment\AttachmentIndex;

/**
 * The thumbnail of a modification's or part's row (spec.md §7.39
 * *Photos*): its earliest attached image. No data of its own.
 */
final readonly class PartThumbnails
{
    /**
     * @param array<string, Attachment> $byOwner keyed "modification:12" / "part:7"
     */
    private function __construct(private array $byOwner)
    {
    }

    /**
     * @param callable(Attachment): bool $visible whether the viewer may have the file (AttachmentGuard)
     */
    public static function from(AttachmentIndex $index, callable $visible): self
    {
        return new self(array_filter(
            $index->firstImages([AttachmentOwner::Modification, AttachmentOwner::Part]),
            $visible,
        ));
    }

    public function of(string $type, int $id): ?Attachment
    {
        return $this->byOwner[$type . ':' . $id] ?? null;
    }
}
