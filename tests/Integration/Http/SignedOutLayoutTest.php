<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\SingleSignOn;
use Logbook\Tests\Support\TestBrowser;

/**
 * One signed-out layout (spec.md §7.9 *Signed-out pages*, Phase 33.2): the
 * mark above one card, a show / hide button added by JS to every password
 * field, and a JS-only checklist under a new password.
 */
final class SignedOutLayoutTest extends AppTestCase
{
    use SingleSignOn;

    public function testSignInWithPasswordOnly(): void
    {
        $app = $this->createApp(['MAIL_HOST' => '']);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $html = self::body((new TestBrowser($app))->get('/login'));

        self::assertStringContainsString('class="auth__brand"', $html);
        self::assertStringNotContainsString('data-sso-start', $html);
        self::assertStringNotContainsString('/forgot-password', $html, 'email off: no forgotten link');
        // Show / hide: hidden until JS, tied to the field, named, a toggle.
        $reveal = '<button type="button" class="input-reveal__btn" data-password-reveal'
            . ' aria-controls="f-password" aria-pressed="false" hidden>';
        self::assertMatchesRegularExpression(
            '~id="f-password" name="password" type="password".*?' . preg_quote($reveal, '~') . '.*?Show password~s',
            $html,
        );
    }

    public function testSignInWithSingleSignOnAndPassword(): void
    {
        [$app] = $this->ssoApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $html = self::body((new TestBrowser($app))->get('/login'));

        self::assertStringContainsString('class="auth__brand"', $html);
        self::assertLessThan(strpos($html, 'id="f-password"'), strpos($html, 'data-sso-start'), 'the button first');
    }

    public function testSignInWithSingleSignOnOnly(): void
    {
        [$app] = $this->ssoApp(['AUTH_LOCAL_LOGIN' => 'false']);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $html = self::body((new TestBrowser($app))->get('/login'));

        self::assertStringContainsString('class="auth__brand"', $html);
        self::assertStringContainsString('data-sso-start', $html);
        self::assertStringNotContainsString('data-password-reveal', $html, 'no password field at all');
    }

    public function testSetupHasTheChecklistOfTheAppsOwnRules(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $html = self::body((new TestBrowser($app))->get('/setup'));

        self::assertStringContainsString('class="auth__brand"', $html);
        self::assertMatchesRegularExpression(
            '~<ul class="password-rules" data-password-rules'
            . ' data-password="password" data-confirm="password_confirm"[^>]* hidden>~',
            $html,
        );
        self::assertStringContainsString('At least 8 characters', $html);
        self::assertStringContainsString('Both passwords match', $html);
        self::assertStringNotContainsString('letter and a number', $html, 'not one of the app\'s rules (#168)');
        self::assertSame(2, substr_count($html, 'data-password-reveal'));
    }

    public function testSignedInTheCardsBrandGivesWayToTheSidebar(): void
    {
        $html = self::body($this->signedIn($this->createApp())->get('/profile'));

        self::assertStringNotContainsString('class="auth__brand"', $html);
        self::assertStringContainsString(
            'data-password-rules data-password="new_password" data-confirm="new_password_confirm"',
            $html,
        );
    }
}
