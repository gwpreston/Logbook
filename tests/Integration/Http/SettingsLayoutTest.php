<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Tests\Support\AppTestCase;

/**
 * Settings as one page of anchored groups (spec.md §8 *Settings layout*,
 * *Unit presets*, Phase 33.2).
 */
final class SettingsLayoutTest extends AppTestCase
{
    private const array GROUPS = [
        'account', 'preferences', 'reminders', 'driving', 'data', 'developers', 'admin', 'installation',
    ];

    public function testAnAdminSeesEveryGroupInOrderEachAnAnchor(): void
    {
        $html = self::body($this->signedIn($this->createApp())->get('/settings'));

        self::assertSame(self::GROUPS, self::groupIds($html));
        self::assertStringNotContainsString('settings.group.', $html, 'every label translated');
        foreach (['/settings/users', '/settings/modules', '/settings/backup', '/settings/jobs', '/settings/api-keys'] as $link) {
            self::assertStringContainsString('href="' . $link . '"', $html, $link);
        }
        // *Account* is one row to the profile page (#172); its forms are there.
        self::assertStringContainsString('href="/profile"', self::group($html, 'account'));
        $moved = ['data-account-card', 'action="/settings/email"', 'action="/settings/avatar"', 'action="/settings/password"'];
        foreach ($moved as $gone) {
            self::assertStringNotContainsString($gone, $html, $gone);
        }
    }

    public function testTheProfilePageHoldsTheUsersOwnAccount(): void
    {
        $html = self::body($this->signedIn($this->createApp())->get('/profile'));

        self::assertStringContainsString('<title>Profile · Logbook</title>', $html);
        self::assertMatchesRegularExpression('~data-account-card>.*?Pat Owner.*?action="/logout"~s', $html);
        foreach (['action="/settings/email"', 'action="/settings/avatar"', 'action="/settings/password"'] as $form) {
            self::assertStringContainsString($form, $html, $form);
        }
        self::assertStringNotContainsString('name="distance_unit"', $html, 'preferences stay on Settings (#170)');
        // Reached from the sidebar's name and the narrow top bar's avatar, both current here.
        self::assertMatchesRegularExpression('~<a class="sidebar__user" href="/profile" aria-current="page">~', $html);
        self::assertMatchesRegularExpression('~<a class="icon-btn topbar__profile" href="/profile" aria-current="page"~', $html);
    }

    public function testAccountFormsComeBackToTheProfile(): void
    {
        $browser = $this->signedIn($this->createApp());
        $browser->get('/profile');

        $saved = $browser->post('/settings/avatar/remove');
        self::assertSame('/profile', $saved->getHeaderLine('Location'));
        $wrong = $browser->post('/settings/password', [
            'current_password' => 'not it',
            'new_password' => 'a brand new passphrase',
            'new_password_confirm' => 'a brand new passphrase',
        ]);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString('<title>Profile · Logbook</title>', self::body($wrong));
    }

    public function testRemindersAndNotificationsHoldsOnlyReminders(): void
    {
        $html = self::body($this->signedIn($this->createApp())->get('/settings'));

        $reminders = self::group($html, 'reminders');
        self::assertStringContainsString('href="/settings/reminders"', $reminders);
        $elsewhere = ['/settings/tyres', '/settings/trips', '/settings/places', '/settings/import-app', '/settings/api-keys'];
        foreach ($elsewhere as $link) {
            self::assertStringNotContainsString($link, $reminders, $link);
        }
        self::assertStringContainsString('href="/settings/tyres"', self::group($html, 'driving'));
        self::assertStringContainsString('href="/settings/places"', self::group($html, 'driving'));
        self::assertStringContainsString('href="/settings/import-app"', self::group($html, 'data'));
        self::assertStringContainsString('href="/settings/api-keys"', self::group($html, 'developers'));
    }

    public function testAMemberSeesNoAdministrationAndNoAdminLinks(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $this->createMember($app);
        $html = self::body($this->browserFor($app, 'partner')->get('/settings'));

        self::assertSame(array_values(array_diff(self::GROUPS, ['admin'])), self::groupIds($html));
        foreach (['/settings/users', '/settings/modules', '/settings/backup', '/settings/jobs'] as $link) {
            self::assertStringNotContainsString('href="' . $link . '"', $html, $link);
        }
        self::assertStringContainsString('href="/settings/api-keys"', $html);
        self::assertStringNotContainsString('href="/settings/ai"', $html);
    }

    public function testWithAiOffThereIsNoAiLinkOrSwitch(): void
    {
        $html = self::body($this->signedIn($this->createApp(['AI_ENABLED' => 'false']))->get('/settings'));

        self::assertStringNotContainsString('href="/settings/ai"', $html);
        self::assertStringNotContainsString('data-ai-use', $html);
        self::assertStringContainsString('href="/settings/users"', self::group($html, 'admin'));
    }

    public function testModulesThatAreOffTakeTheirCardsWithThem(): void
    {
        $browser = $this->signedIn($this->createApp());
        $browser->get('/settings/modules');
        $browser->post('/settings/modules', ['maintenance' => '1', 'reminders' => '1']);
        $html = self::body($browser->get('/settings'));

        self::assertNotContains('driving', self::groupIds($html), 'tyres, trips and fuel stations off');
        self::assertNotContains('data', self::groupIds($html), 'fuel off: nothing to import');
        self::assertStringNotContainsString('href="/settings/import-app"', $html, 'fuel off');
        self::assertStringContainsString('href="/settings/backup"', self::group($html, 'admin'));
    }

    public function testThePresetMatchingTheUnitsIsPressed(): void
    {
        $browser = $this->signedIn($this->createApp());
        $html = self::body($browser->get('/settings'));
        $uk = ['metric' => 'false', 'uk' => 'true', 'us' => 'false'];
        self::assertSame($uk, self::presets($html), 'miles, litres, mpg (UK), mm');

        $browser->post('/settings/preferences', [
            'display_name' => 'Pat',
            'theme' => 'system',
            'distance_unit' => 'km',
            'volume_unit' => 'l',
            'consumption_unit' => 'mpg_uk',
            'depth_unit' => 'mm',
            'currency' => 'GBP',
            'locale' => 'en_GB',
            'timezone' => 'Europe/London',
        ]);
        $mixed = self::body($browser->get('/settings'));
        self::assertSame(['metric' => 'false', 'uk' => 'false', 'us' => 'false'], self::presets($mixed), 'km with mpg: none');
    }

    /**
     * @return list<string>
     */
    private static function groupIds(string $html): array
    {
        preg_match_all('~<section class="settings-group" id="([a-z]+)"~', $html, $matches);

        return $matches[1];
    }

    private static function group(string $html, string $id): string
    {
        $start = strpos($html, '<section class="settings-group" id="' . $id . '"');
        self::assertNotFalse($start, $id);
        $end = strpos($html, '<section class="settings-group"', $start + 1);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    /**
     * @return array<string, string>
     */
    private static function presets(string $html): array
    {
        $preset = '~data-unit-preset aria-pressed="(true|false)"\s+data-distance-unit="([a-z]+)"~';
        preg_match_all($preset, $html, $matches, PREG_SET_ORDER);
        $pressed = [];
        foreach ($matches as $i => $match) {
            $pressed[['metric', 'uk', 'us'][$i]] = $match[1];
        }

        return $pressed;
    }
}
