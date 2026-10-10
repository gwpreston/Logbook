<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use Logbook\Tests\Support\BriefingTestCase;

/**
 * Regressions from Phase 43's bug hunt (spec.md §7.11 *The monthly
 * briefing*): percentages from the figures as shown, and no empty digest
 * to the server's webhook (#366).
 */
final class MonthlyBriefingReviewTest extends BriefingTestCase
{
    private const array WIN = [
        '2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02',
        '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08',
    ];

    /** The comparison must not be worked out against an average that is shown as 0.00. */
    public function testPercentIsWorkedOutFromTheFiguresShown(): void
    {
        $this->start();
        $v = $this->car();
        $this->monthlyReadings($v, ['2025-08', ...self::WIN, '2026-09'], 1000, 300);
        $this->spend($v, '2025-09-12', '0.05');
        $this->spend($v, '2026-09-12', '50');
        $this->runTasks();

        // An average of £0.004 a month shows as £0.00: no "about 1199804% more" beside it.
        $text = $this->digestText();
        self::assertStringNotContainsString('(£0.00)', $text, $text);
    }

    /** A digest whose every figure is withheld from the server webhook must not be sent to it. */
    public function testServerWebhookGetsNothingWhenAllItWouldCarryIsWithheld(): void
    {
        $this->start();
        $v = $this->car();
        $this->spend($v, '2026-09-12', '50');
        $this->runTasks();

        // Spend is all there is, and the server's webhook gets none (#366), so nothing at all.
        self::assertStringContainsString('£50.00 spent', $this->digestText());
        $sent = [];
        foreach ($this->http->to('https://hooks.test') as $r) {
            $sent[] = $r['url'];
        }
        self::assertNotContains('https://hooks.test/logbook', $sent);
    }
}
