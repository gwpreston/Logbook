<?php

declare(strict_types=1);

namespace Logbook\Service\User;

/**
 * One account email (spec.md §7.9: reset links, confirmations and
 * notices), already translated: rendered as plain text and as HTML without
 * remote images.
 */
final readonly class AccountMail
{
    /**
     * @param list<string> $paragraphs before the link
     * @param list<string> $after      after the link
     */
    public function __construct(
        public string $subject,
        public array $paragraphs,
        public ?string $link = null,
        public ?string $linkLabel = null,
        public array $after = [],
    ) {
    }
}
