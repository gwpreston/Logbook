<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * The monthly digest's optional sections (spec.md §7.11 *The monthly
 * briefing*, Phase 43): what the user ticks under *Include*. *What's due*
 * is always sent, so it is not one. Case order is the order they appear
 * in the message.
 */
enum DigestSection: string
{
    case Attention = 'attention';
    case LastMonth = 'last_month';
    case Insights = 'insights';

    /**
     * The stored list read back: unknown values are ignored; null (never
     * chosen) means every section.
     *
     * @return list<self>|null
     */
    public static function fromStored(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $sections = [];
        foreach ($value as $item) {
            $section = is_string($item) ? self::tryFrom($item) : null;
            if ($section !== null && !in_array($section, $sections, true)) {
                $sections[] = $section;
            }
        }
        usort($sections, static fn (self $a, self $b): int => self::position($a) <=> self::position($b));

        return $sections;
    }

    private static function position(self $section): int
    {
        return (int) array_search($section, self::cases(), true);
    }
}
