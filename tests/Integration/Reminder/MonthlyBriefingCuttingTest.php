<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use DateTimeZone;
use Logbook\Domain\Issue\IssueData;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\Notification\Personal\MessageText;
use Logbook\Service\Notification\Personal\PushoverSender;
use Logbook\Tests\Support\BriefingTestCase;

/**
 * A short channel keeps what matters (spec.md §7.11 *The monthly
 * briefing*, *Limits*, Phase 43.3): on Pushover's 1,024 characters the
 * due work and *Needs attention* come first, the cut falls between lines
 * and ends "…and N more", and the link goes in the message's `url`.
 */
final class MonthlyBriefingCuttingTest extends BriefingTestCase
{
    private const string PUSHOVER_TOKEN = 'azGDORePK8gMaC0QOYAMyEEuzJnyUi';
    private const string PUSHOVER_USER = 'uQiRzpo4DXghDmr9QzzfQu27cmVRsG';

    public function testAPushoverDigestKeepsDueAndAttentionAndEndsWithTheCount(): void
    {
        $this->start();
        $owner = $this->owner($this->app);
        $this->giveChannel($this->app, $owner, 'pushover', [], ['token' => self::PUSHOVER_TOKEN, 'user' => self::PUSHOVER_USER]);
        $this->http->bodyFor['https://api.pushover.net'] = '{"status":1,"request":"r"}';
        $golf = $this->car();
        $this->document($this->app, $golf, '2026-10-20');
        $this->service($this->app, IssueService::class)->create(
            $golf,
            new IssueData(self::date('2026-09-15'), 'Rattle', IssueStatus::Open),
            new DateTimeZone('Europe/London'),
        );
        foreach (range(1, 10) as $i) {
            $vehicle = $this->car('Model ' . $i);
            $this->reading($vehicle, '10000', '2026-08-20');
            $this->reading($vehicle, '10400', '2026-09-10');
            $this->spend($vehicle, '2026-09-05', (string) (20 + $i));
        }

        $this->runTasks();

        $digests = array_values(array_filter(
            $this->http->to('https://api.pushover.net'),
            static fn (array $r): bool => str_starts_with(self::text($r['form']['title'] ?? ''), 'Due in'),
        ));
        self::assertCount(1, $digests);
        $form = $digests[0]['form'];
        $message = $form['message'] ?? null;
        self::assertIsString($message);
        self::assertLessThanOrEqual(PushoverSender::LIMIT, MessageText::length($message));
        self::assertStringContainsString('Insurance — Volkswagen Golf', $message, 'what is due is kept');
        self::assertStringContainsString('• Volkswagen Golf: 1 open issue', $message, 'so is what needs attention');
        self::assertLessThan(
            (int) strpos($message, 'open issue'),
            (int) strpos($message, 'Insurance'),
            'due before attention',
        );
        self::assertMatchesRegularExpression('/\n…and \d+ more$/u', $message);
        self::assertStringNotContainsString('Model 10', $message, 'the last lines are what is cut');
        self::assertSame('https://garage.example/reminders', $form['url'] ?? null);

        // The email, with no limit, has it all.
        $mail = $this->digestText();
        self::assertStringContainsString('Model 10', $mail);
        self::assertStringNotContainsString('…and', $mail);
    }
}
