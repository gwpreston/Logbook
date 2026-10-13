<?php

declare(strict_types=1);

namespace Logbook\Domain\Access;

/**
 * What one share allows, in plain words (spec.md §7.21): the *Can* lines
 * and the *Can't* lines, in the spec's order.
 */
final readonly class ShareAllowance
{
    /**
     * @param list<ShareSummaryLine> $lines
     */
    public function __construct(
        public ShareLevel $level,
        public bool $canSeeCosts,
        public bool $notify,
        public array $lines,
    ) {
    }

    /**
     * @return list<ShareSummaryLine>
     */
    public function can(): array
    {
        return array_values(array_filter($this->lines, static fn (ShareSummaryLine $line): bool => $line->can));
    }

    /**
     * @return list<ShareSummaryLine>
     */
    public function cannot(): array
    {
        return array_values(array_filter($this->lines, static fn (ShareSummaryLine $line): bool => !$line->can));
    }

    /**
     * The access here that `$before` didn't give: what a save widened. A
     * line within one `$before` already had (own entries' amounts, when
     * costs were on) adds nothing, and reminders aren't access.
     *
     * @return list<ShareSummaryLine>
     */
    public function gainedSince(self $before): array
    {
        $had = array_map(static fn (ShareSummaryLine $line): string => $line->key, $before->can());

        return array_values(array_filter(
            $this->can(),
            static fn (ShareSummaryLine $line): bool => $line->access
                && !in_array($line->key, $had, true)
                && ($line->within === null || !in_array($line->within, $had, true)),
        ));
    }
}
