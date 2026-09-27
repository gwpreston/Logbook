<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Repository\UserRepository;
use Logbook\Support\Display\Theme;
use Logbook\Tests\Support\AppTestCase;

final class PreferencesTest extends AppTestCase
{
    private const array US = [
        'display_name' => 'Pat',
        'theme' => 'system',
        'distance_unit' => 'mi',
        'volume_unit' => 'gal_us',
        'consumption_unit' => 'mpg_us',
        'currency' => 'USD',
        'locale' => 'en_US',
        'timezone' => 'America/Los_Angeles',
    ];

    public function testSettingsShowTheSavedPreferences(): void
    {
        $html = self::body($this->signedIn($this->createApp())->get('/settings'));

        self::assertStringContainsString('value="Pat Owner"', $html);
        self::assertMatchesRegularExpression('~value="mi" checked~', $html);
        self::assertMatchesRegularExpression('~value="mpg_uk" checked~', $html);
        self::assertMatchesRegularExpression('~<option value="Europe/London" selected>~', $html);
        self::assertMatchesRegularExpression('~<option value="en_GB" selected>English \(United Kingdom\)</option>~', $html);
        self::assertStringContainsString('7,671 mi', $html, 'preview in miles');
        self::assertStringContainsString('£1,234.50', $html);
    }

    public function testChangesApplyImmediatelyAcrossTheUi(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->get('/settings');

        $response = $browser->post('/settings/preferences', self::US);
        self::assertSame('/settings', $response->getHeaderLine('Location'));

        $page = $browser->follow($response);
        $html = self::body($page);
        self::assertSame('en-US', $page->getHeaderLine('Content-Language'));
        self::assertStringContainsString('Your preferences were saved.', $html);
        self::assertStringContainsString('12.02 US gal', $html, '45.5 L in US gallons');
        self::assertStringContainsString('$1,234.50', $html);
        self::assertStringContainsString('Hello, Pat', self::body($browser->get('/')));

        $user = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($user);
        self::assertSame('America/Los_Angeles', $user->preferences->timezone);
        self::assertSame('USD', $user->preferences->currency);

        // Metric with km/L: mix and match is allowed.
        $metric = ['distance_unit' => 'km', 'volume_unit' => 'l', 'consumption_unit' => 'km_per_l'];
        $browser->post('/settings/preferences', $metric + self::US);
        self::assertStringContainsString('12,346 km', self::body($browser->get('/settings')));
    }

    public function testInvalidPreferencesAreRejectedInPlace(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->get('/settings');

        $invalid = ['timezone' => 'Mars/Olympus_Mons', 'currency' => 'DOGE', 'display_name' => ''];
        $response = $browser->post('/settings/preferences', $invalid + self::US);
        $html = self::body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(3, substr_count($html, 'class="field__error"'));
        self::assertStringContainsString('This field is required.', $html);
        self::assertStringContainsString('Choose one of the options.', $html);

        $user = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($user);
        self::assertSame('Europe/London', $user->preferences->timezone, 'nothing saved');
    }

    public function testThemeToggleSavesThePreferenceAndReturns(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->get('/garage');

        $response = $browser->post('/settings/theme', ['theme' => 'dark', 'return_to' => '/garage']);
        self::assertSame('/garage', $response->getHeaderLine('Location'));
        self::assertStringContainsString(
            '<html lang="en-GB" data-theme-pref="dark" data-theme="dark">',
            self::body($browser->follow($response)),
        );

        $user = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertSame(Theme::Dark, $user?->preferences->theme);

        $offsite = $browser->post('/settings/theme', ['theme' => 'light', 'return_to' => 'https://evil.example/']);
        self::assertSame('/', $offsite->getHeaderLine('Location'));
        self::assertSame(400, $browser->post('/settings/theme', ['theme' => 'neon'])->getStatusCode());

        // A form re-shown after a failed POST offers no POST-only address to return to.
        $failed = self::body($browser->post('/settings/password', ['current_password' => 'nope']));
        self::assertStringContainsString('name="return_to" value=""', $failed);
        self::assertStringContainsString('name="return_to" value="/garage"', self::body($browser->get('/garage')));
    }
}
