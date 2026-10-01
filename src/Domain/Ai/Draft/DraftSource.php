<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Draft;

/**
 * Where a draft came from (spec.md §6 AiDraft): Ask Logbook's chat, or an
 * MCP client's draft tool (§7.28), which waits longer because its user
 * reviews it later, in Logbook.
 */
enum DraftSource: string
{
    case Ask = 'ask';
    case Mcp = 'mcp';

    /**
     * How long the draft waits for *Add*.
     */
    public function ttlSeconds(): int
    {
        return match ($this) {
            self::Ask => 3600,
            self::Mcp => 7 * 86400,
        };
    }
}
