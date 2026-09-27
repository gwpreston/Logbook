<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support;

use DateTimeImmutable;
use Logbook\Support\Security\PasswordHasher;
use Logbook\Support\Security\SafeRedirect;
use Logbook\Support\Session\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityAndSessionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function redirects(): iterable
    {
        yield 'local path' => ['/vehicles/3?tab=fuel', '', '/vehicles/3?tab=fuel'];
        yield 'under base path' => ['/logbook/garage', '/logbook', '/logbook/garage'];
        yield 'base path root' => ['/logbook', '/logbook', '/logbook'];
        yield 'outside base path' => ['/other/app', '/logbook', null];
        yield 'prefix trick' => ['/logbookevil', '/logbook', null];
        yield 'absolute URL' => ['https://evil.example/', '', null];
        yield 'protocol-relative' => ['//evil.example/', '', null];
        yield 'backslash' => ['/\\evil.example', '', null];
        yield 'relative' => ['garage', '', null];
        yield 'control characters' => ["/garage\r\nLocation: x", '', null];
        yield 'fragment' => ['/garage#x', '', null];
    }

    #[DataProvider('redirects')]
    public function testSafeRedirect(string $target, string $basePath, ?string $expected): void
    {
        self::assertSame($expected, SafeRedirect::localPath($target, $basePath));
    }

    public function testPasswordHashingUsesArgon2id(): void
    {
        $cheap = new PasswordHasher(['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
        $hash = $cheap->hash('correct horse battery staple');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($cheap->verify('correct horse battery staple', $hash));
        self::assertFalse($cheap->verify('Correct horse battery staple', $hash));
        self::assertFalse($cheap->needsRehash($hash));
        // PHP's default cost is higher, so the cheap hash would be upgraded.
        self::assertTrue((new PasswordHasher())->needsRehash($hash));
    }

    public function testSessionStartsEmptyAndClean(): void
    {
        $session = Session::start();

        self::assertTrue($session->isEmpty());
        self::assertFalse($session->isDirty());
        self::assertFalse($session->isPersisted());
        self::assertNull($session->userId());
    }

    public function testSettingTheSameValueIsNotAChange(): void
    {
        $session = Session::resume('token', ['a' => 1], new DateTimeImmutable());

        $session->set('a', 1);
        self::assertFalse($session->isDirty());

        $session->set('a', 2);
        self::assertTrue($session->isDirty());
    }

    public function testSignInRegeneratesAndDropsCsrfTokens(): void
    {
        $session = Session::resume('token', [], new DateTimeImmutable());
        $session->setCsrfTokens(['csrf1' => 'abc']);

        $session->signIn(7);

        self::assertTrue($session->wasRegenerated());
        self::assertSame(7, $session->userId());
        self::assertSame([], $session->csrfTokens());
    }

    public function testDestroyForgetsEverythingButKeepsLaterFlashes(): void
    {
        $session = Session::resume('token', [], new DateTimeImmutable());
        $session->signIn(7);
        $session->destroy();
        $session->flash('success', 'auth.signed_out');

        self::assertNull($session->userId());
        self::assertSame([['type' => 'success', 'key' => 'auth.signed_out', 'params' => []]], $session->takeFlashes());
        self::assertSame([], $session->takeFlashes(), 'flashes are shown once');
        self::assertTrue($session->isEmpty());
    }
}
