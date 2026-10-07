<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

/**
 * One field of a channel definition (spec.md §7.11 *Definitions*): its
 * label and hint are translation keys.
 */
final readonly class ChannelField
{
    public function __construct(
        public string $name,
        public string $label,
        public FieldType $type,
        public bool $required = false,
        public ?string $hint = null,
        public ?string $placeholder = null,
        public int $maxLength = 500,
        /** An Integer field's range and its value when left empty. */
        public int $min = 0,
        public int $max = 0,
        public ?int $default = null,
    ) {
    }

    public function isSecret(): bool
    {
        return $this->type === FieldType::Secret;
    }
}
