<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use Logbook\Domain\Feature\Feature;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Notification\DigestSection;
use Logbook\Service\Notification\NotificationPreferences;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Tests\Support\BriefingTestCase;
use Logbook\Tests\Support\Html;

/**
 * *Include* on Settings → Reminders (spec.md §7.11 *The monthly briefing*,
 * #363, Phase 43.3): the fieldset, saving with a plain form post (no JS),
 * an absent `digest_include_shown` keeping the saved choice, and a 422
 * re-render keeping what was ticked.
 */
final class MonthlyBriefingSettingsTest extends BriefingTestCase
{
    private const array BASE = [
        'schedule_days' => '30',
        'schedule_distance' => '621',
        'document_days' => '30',
        'manual_days' => '7',
        'channels' => ['email'],
        'digest' => '1',
    ];

    public function testTheIncludeFieldsetShowsEveryBoxTickedByDefault(): void
    {
        $this->start();

        $page = self::body($this->browser->get('/settings/reminders'));

        self::assertStringContainsString('<legend class="field__label">Include</legend>', $page);
        self::assertStringContainsString('name="digest_include_shown" value="1"', $page);
        self::assertStringContainsString('What’s due is always included.', $page);
        self::assertSame([], $this->unticked($page, 'Include box'), 'every box on by default');
        foreach (['attention', 'last_month', 'insights'] as $value) {
            self::assertMatchesRegularExpression('/name="digest_include\[\]" value="' . $value . '" checked/', $page);
        }
        self::assertStringContainsString('Needs attention and open issues', $page);
        self::assertStringContainsString('Last month', $page);
        self::assertStringContainsString('>Insights</span>', $page);
        self::assertDoesNotMatchRegularExpression('/name="digest_include\[\]" value="due"/', $page, 'what is due is not a box');
    }

    public function testAPlainPostSavesTheTickedBoxes(): void
    {
        $this->start();

        $saved = $this->browser->post('/settings/reminders', self::BASE + [
            'digest_include_shown' => '1',
            'digest_include' => ['insights', 'last_month'],
        ]);

        self::assertSame(303, $saved->getStatusCode());
        $preferences = $this->preferences();
        self::assertSame([DigestSection::LastMonth, DigestSection::Insights], $preferences->digestSections);
        self::assertTrue($preferences->digest, 'digest stays a boolean');
        $page = self::body($this->browser->get('/settings/reminders'));
        self::assertMatchesRegularExpression('/value="last_month" checked/', $page);
        self::assertMatchesRegularExpression('/value="insights" checked/', $page);
        self::assertDoesNotMatchRegularExpression('/value="attention" checked/', $page);
    }

    public function testUntickingEverythingSavesAnEmptyChoice(): void
    {
        $this->start();

        $this->browser->post('/settings/reminders', self::BASE + ['digest_include_shown' => '1']);

        self::assertSame([], $this->preferences()->digestSections);
        $page = self::body($this->browser->get('/settings/reminders'));
        self::assertDoesNotMatchRegularExpression('/name="digest_include\[\]"[^>]* checked/', $page);
    }

    public function testAPostWithoutTheShownMarkerKeepsTheSavedChoice(): void
    {
        $this->start();
        $this->browser->post('/settings/reminders', self::BASE + [
            'digest_include_shown' => '1',
            'digest_include' => ['attention'],
        ]);
        self::assertSame([DigestSection::Attention], $this->preferences()->digestSections);

        $saved = $this->browser->post('/settings/reminders', self::BASE);

        self::assertSame(303, $saved->getStatusCode());
        self::assertSame([DigestSection::Attention], $this->preferences()->digestSections);
    }

    public function testUnknownValuesAreIgnoredWhenSaving(): void
    {
        $this->start();

        $this->browser->post('/settings/reminders', self::BASE + [
            'digest_include_shown' => '1',
            'digest_include' => ['insights', 'due', 'nonsense'],
        ]);

        self::assertSame([DigestSection::Insights], $this->preferences()->digestSections);
    }

    public function testAnInvalidPostShowsThePostedTicksAgain(): void
    {
        $this->start();
        $this->saveAttentionOnly();

        $invalid = $this->browser->post('/settings/reminders', [
            'schedule_days' => '400',
            'digest_include_shown' => '1',
            'digest_include' => ['insights'],
        ] + self::BASE);

        self::assertSame(422, $invalid->getStatusCode());
        $page = self::body($invalid);
        self::assertMatchesRegularExpression('/value="insights" checked/', $page);
        self::assertDoesNotMatchRegularExpression('/value="attention" checked/', $page);
        self::assertDoesNotMatchRegularExpression('/value="last_month" checked/', $page);
        self::assertSame([DigestSection::Attention], $this->preferences()->digestSections, 'nothing was saved');
    }

    public function testAnInvalidPostWithNothingTickedShowsNoneTicked(): void
    {
        $this->start();

        $post = ['schedule_days' => '400', 'digest_include_shown' => '1'];
        $invalid = $this->browser->post('/settings/reminders', $post + self::BASE);

        self::assertSame(422, $invalid->getStatusCode());
        self::assertDoesNotMatchRegularExpression('/name="digest_include\[\]"[^>]* checked/', self::body($invalid));
        self::assertNull($this->preferences()->digestSections, 'nothing was saved');
    }

    public function testTheFieldsetAndTheChoiceAreLeftAloneWithRemindersOff(): void
    {
        $this->start();
        $this->saveAttentionOnly();
        $features = $this->service($this->app, FeatureToggles::class);
        $features->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Reminders)));

        $page = $this->browser->get('/settings/reminders');
        self::assertStringNotContainsString('digest_include', self::body($page));
        $saved = $this->browser->post('/settings/reminders', self::BASE);
        self::assertContains($saved->getStatusCode(), [303, 404]);

        self::assertSame([DigestSection::Attention], $this->preferences()->digestSections);
    }

    private function saveAttentionOnly(): void
    {
        $this->browser->post('/settings/reminders', self::BASE + [
            'digest_include_shown' => '1',
            'digest_include' => ['attention'],
        ]);
    }

    private function preferences(): NotificationPreferences
    {
        return $this->service($this->app, ReminderSettingsStore::class)
            ->notificationPreferences($this->owner($this->app)->id);
    }

    /**
     * The digest checkboxes that are not ticked.
     *
     * @return list<string>
     */
    private function unticked(string $page, string $label): array
    {
        $boxes = Html::document($page)->querySelectorAll('input[name="digest_include[]"]');
        self::assertCount(3, $boxes, $label . ': three boxes');
        $off = [];
        foreach ($boxes as $box) {
            if (!$box->hasAttribute('checked')) {
                $off[] = (string) $box->getAttribute('value');
            }
        }

        return $off;
    }
}
