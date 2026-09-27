<?php

declare(strict_types=1);

namespace Logbook\Service\Attachment;

use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;

/**
 * A vehicle's attachments grouped by the entry they belong to, so a list page
 * can show each row's files with one query. Templates call
 * `attachments.of('maintenance', entry.id)`.
 */
final readonly class AttachmentIndex
{
    /** @var array<string, list<Attachment>> */
    private array $byOwner;

    /**
     * @param list<Attachment> $attachments
     */
    public function __construct(array $attachments)
    {
        $byOwner = [];
        foreach ($attachments as $attachment) {
            $byOwner[self::key($attachment->ownerType, $attachment->ownerId)][] = $attachment;
        }
        $this->byOwner = $byOwner;
    }

    /**
     * @return list<Attachment>
     */
    public function of(AttachmentOwner|string $type, int $ownerId): array
    {
        return $this->byOwner[self::key($type, $ownerId)] ?? [];
    }

    private static function key(AttachmentOwner|string $type, int $ownerId): string
    {
        return ($type instanceof AttachmentOwner ? $type->value : $type) . ':' . $ownerId;
    }
}
