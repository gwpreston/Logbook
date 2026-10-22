<?php

declare(strict_types=1);

namespace Logbook\Support\Database;

/**
 * A `LIKE` pattern that matches text literally on every engine (K51).
 *
 * `!` is the escape character, named with `ESCAPE '!'` in the query: SQLite
 * has no default escape, and a backslash in a string literal means an escape
 * on MySQL but a plain character on PostgreSQL, so `ESCAPE '\'` can't be
 * written the same on both (CLAUDE.md §6).
 */
final class LikePattern
{
    /** Append to a `LIKE :param` comparison. */
    public const string ESCAPE = " ESCAPE '!'";

    /**
     * `%text%`, lowered, with `!`, `%` and `_` in the text taken literally.
     * Compare it with `LOWER(column)`.
     */
    public static function containsLowered(string $text): string
    {
        return '%' . strtr(mb_strtolower($text, 'UTF-8'), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
}
