<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai;

use Logbook\Service\Ai\SecretBox;
use Logbook\Service\Ai\SecretUnreadable;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use PHPUnit\Framework\TestCase;

/**
 * AI secrets at rest (spec.md §7.25 *Secrets*): sealed with a key from
 * SESSION_SECRET, or an `env:` reference read at call time.
 */
final class SecretBoxTest extends TestCase
{
    private const string SECRET = '0123456789abcdef0123456789abcdef';

    public function testASealedKeyOpensWithTheSameSecretAndNeverContainsTheKey(): void
    {
        $box = self::box(self::SECRET);
        $stored = $box->store('sk-live-abc123');

        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString('sk-live-abc123', $stored);
        self::assertStringNotContainsString(base64_encode('sk-live-abc123'), $stored);
        self::assertSame('sk-live-abc123', $box->open('api_key', $stored));
    }

    public function testEachSealUsesAFreshNonce(): void
    {
        $box = self::box(self::SECRET);

        self::assertNotSame($box->store('same'), $box->store('same'));
    }

    public function testAnotherSessionSecretCannotOpenIt(): void
    {
        $stored = self::box(self::SECRET)->store('sk-live-abc123');

        try {
            self::box('another-secret-another-secret-xx')->open('api_key', $stored);
            self::fail('A key sealed with another SESSION_SECRET must not open.');
        } catch (SecretUnreadable $e) {
            self::assertSame('api_key', $e->slot);
            self::assertNull($e->variable);
        }
    }

    public function testATamperedValueIsUnreadable(): void
    {
        $box = self::box(self::SECRET);
        $stored = $box->store('sk-live-abc123');
        $raw = base64_decode(substr($stored, 3), true);
        self::assertIsString($raw);
        $raw[30] = $raw[30] === 'a' ? 'b' : 'a';

        $this->expectException(SecretUnreadable::class);
        $box->open('api_key', 'v1:' . base64_encode($raw));
    }

    public function testAReferenceIsStoredAsGivenAndReadAtCallTime(): void
    {
        $box = self::box(self::SECRET, ['OPENAI_API_KEY' => 'sk-from-env']);

        self::assertTrue(SecretBox::isReference('env:OPENAI_API_KEY'));
        self::assertSame('env:OPENAI_API_KEY', $box->store(' env:OPENAI_API_KEY '));
        self::assertSame('sk-from-env', $box->open('api_key', 'env:OPENAI_API_KEY'));
        self::assertSame('OPENAI_API_KEY', SecretBox::variable('env:OPENAI_API_KEY'));
    }

    public function testAnUnsetVariableIsUnreadableAndNamesIt(): void
    {
        try {
            self::box(self::SECRET)->open('header:Authorization', 'env:PROXY_AUTH');
            self::fail('An unset variable must not open.');
        } catch (SecretUnreadable $e) {
            self::assertSame('PROXY_AUTH', $e->variable);
            self::assertSame('header:Authorization', $e->slot);
        }
    }

    public function testWithoutASessionSecretOnlyReferencesCanBeStored(): void
    {
        $box = self::box('', ['KEY' => 'k']);

        self::assertFalse($box->canStore('sk-live-abc123'));
        self::assertTrue($box->canStore('env:KEY'));
        self::assertTrue(self::box(self::SECRET)->canStore('sk-live-abc123'));
    }

    public function testNotAReferenceIsSealed(): void
    {
        self::assertFalse(SecretBox::isReference('env:'));
        self::assertFalse(SecretBox::isReference('env:1ABC'));
        self::assertFalse(SecretBox::isReference('ENV:KEY'));
        self::assertStringStartsWith('v1:', self::box(self::SECRET)->store('env:has space'));
    }

    public function testNotificationSecretsHaveTheirOwnKey(): void
    {
        $ai = self::box(self::SECRET);
        $notify = $ai->withInfo(SecretBox::NOTIFY);
        $stored = $notify->store('smtp-password');

        self::assertSame('smtp-password', $notify->open('smtp_password', $stored));
        $this->expectException(SecretUnreadable::class);
        $ai->open('smtp_password', $stored);
    }

    public function testTheMessageNamesTheSecretNotItsValue(): void
    {
        $stored = self::box(self::SECRET)->withInfo(SecretBox::NOTIFY)->store('smtp-password');
        try {
            self::box('another-secret-another-secret-xx')->withInfo(SecretBox::NOTIFY)->open('smtp_password', $stored);
            self::fail('Another SESSION_SECRET must not open it.');
        } catch (SecretUnreadable $e) {
            self::assertSame('The secret "smtp_password" cannot be decrypted with this SESSION_SECRET.', $e->getMessage());
        }
    }

    /**
     * @param array<string, string> $env
     */
    private static function box(string $secret, array $env = []): SecretBox
    {
        return new SecretBox(AppSettings::fromEnv(new Env(['SESSION_SECRET' => $secret] + $env), '/tmp'));
    }
}
