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

    /**
     * Each entry's earliest attached image, for these owner types (spec.md
     * §7.39 *Photos*: the row's thumbnail).
     *
     * @param list<AttachmentOwner> $types
     * @return array<string, Attachment> keyed "{type}:{id}"
     */
    public function firstImages(array $types): array
    {
        $wanted = array_map(static fn (AttachmentOwner $t): string => $t->value . ':', $types);
        $first = [];
        foreach ($this->byOwner as $key => $attachments) {
            if (!in_array(substr($key, 0, (int) strpos($key, ':') + 1), $wanted, true)) {
                continue;
            }
            foreach ($attachments as $attachment) {
                if ($attachment->isImage() && (!isset($first[$key]) || $attachment->id < $first[$key]->id)) {
                    $first[$key] = $attachment;
                }
            }
        }

        return $first;
    }

    private static function key(AttachmentOwner|string $type, int $ownerId): string
    {
        return ($type instanceof AttachmentOwner ? $type->value : $type) . ':' . $ownerId;
    }
}
