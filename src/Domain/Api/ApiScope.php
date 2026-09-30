<?php

declare(strict_types=1);

namespace Logbook\Domain\Api;

/**
 * What an API key may do (spec.md §7.20): read, or read and log fill-ups
 * and readings. Always narrowed further by its user's access.
 */
enum ApiScope: string
{
    case Read = 'read';
    case ReadWrite = 'read_write';

    public function canWrite(): bool
    {
        return $this === self::ReadWrite;
    }

    public function labelKey(): string
    {
        return 'api_keys.scope.' . $this->value;
    }
}
