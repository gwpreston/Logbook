<?php

declare(strict_types=1);

namespace Logbook\Service\Attachment;

use Logbook\Domain\Attachment\AttachmentOwner;

/**
 * Number of files per entry, from one grouped query, for the paperclips on
 * list rows (spec.md §7.12). Entry ids are unique per owner type, so the
 * type and id name an entry whichever vehicle it is on. Templates call
 * `attachment_counts.of('fuel', entry.id)`.
 */
final readonly class AttachmentCounts
{
    /**
     * @param array<string, int> $counts keyed "type:id"
     */
    public function __construct(private array $counts = [])
    {
    }

    public static function key(AttachmentOwner|string $type, int $ownerId): string
    {
        return ($type instanceof AttachmentOwner ? $type->value : $type) . ':' . $ownerId;
    }

    public function of(AttachmentOwner|string $type, ?int $ownerId): int
    {
        return $ownerId === null ? 0 : $this->counts[self::key($type, $ownerId)] ?? 0;
    }
}
