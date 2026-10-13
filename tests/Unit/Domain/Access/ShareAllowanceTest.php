<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Domain\Access;

use Logbook\Domain\Access\ShareAllowance;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Access\ShareSummaryLine;
use PHPUnit\Framework\TestCase;

/**
 * What a share allows (spec.md §7.21): the Can and Can't lists, and what a
 * save widened (#395), which leaves out reminders and a part of a line the
 * share already had.
 */
final class ShareAllowanceTest extends TestCase
{
    public function testSplitsCanFromCannotInOrder(): void
    {
        $allowance = new ShareAllowance(ShareLevel::Log, false, false, [
            new ShareSummaryLine('see', true),
            new ShareSummaryLine('costs', false),
            new ShareSummaryLine('add', true),
            new ShareSummaryLine('own', false),
        ]);

        self::assertSame(['see', 'add'], self::keys($allowance->can()));
        self::assertSame(['costs', 'own'], self::keys($allowance->cannot()));
    }

    public function testGainedNamesOnlyNewAccess(): void
    {
        $before = new ShareAllowance(ShareLevel::Log, false, false, [
            new ShareSummaryLine('see', true),
            new ShareSummaryLine('own_amounts', true, within: 'costs'),
            new ShareSummaryLine('costs', false),
            new ShareSummaryLine('reminders', false, access: false),
        ]);
        $after = new ShareAllowance(ShareLevel::Log, true, true, [
            new ShareSummaryLine('see', true),
            new ShareSummaryLine('costs', true),
            new ShareSummaryLine('reminders', true, access: false),
        ]);

        self::assertSame(['costs'], self::keys($after->gainedSince($before)), 'reminders are not access');
        self::assertSame([], $after->gainedSince($after));
    }

    public function testNarrowingGainsNothing(): void
    {
        // Manage → Log: the own-entry lines appear, but each is part of a
        // line Manage already had.
        $manage = new ShareAllowance(ShareLevel::Manage, true, false, [
            new ShareSummaryLine('change_any', true),
            new ShareSummaryLine('costs', true),
        ]);
        $log = new ShareAllowance(ShareLevel::Log, false, false, [
            new ShareSummaryLine('change_own', true, within: 'change_any'),
            new ShareSummaryLine('change_any', false),
            new ShareSummaryLine('own_amounts', true, within: 'costs'),
            new ShareSummaryLine('costs', false),
        ]);

        self::assertSame([], $log->gainedSince($manage));
        self::assertSame(['change_any', 'costs'], self::keys($manage->gainedSince($log)));
    }

    /**
     * @param list<ShareSummaryLine> $lines
     * @return list<string>
     */
    private static function keys(array $lines): array
    {
        return array_map(static fn (ShareSummaryLine $line): string => $line->key, $lines);
    }
}
