<?php

declare(strict_types=1);

namespace Logbook\Support\Display;

/**
 * The accent colour (spec.md §8): switches the primary / hover / pressed /
 * subtle / focus / first chart series tokens, in both themes. Status colours
 * and the number plate never follow it.
 */
enum Accent: string
{
    case Blue = 'blue';
    case Teal = 'teal';
    case Indigo = 'indigo';
    case Purple = 'purple';

    public const self DEFAULT = self::Blue;

    /**
     * The light-theme accent, for the settings swatches (the stylesheet holds
     * the full token set, dark variants included).
     */
    public function swatch(): string
    {
        return match ($this) {
            self::Blue => '#1667d9',
            self::Teal => '#0b7f86',
            self::Indigo => '#4b4fd6',
            self::Purple => '#7c3fc4',
        };
    }
}
