<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai;

use Logbook\Service\Ai\Redactor;
use PHPUnit\Framework\TestCase;

/**
 * Secrets never reach a page or a log (spec.md §7.25 *Secrets*).
 */
final class RedactorTest extends TestCase
{
    public function testTheConnectionsOwnSecretsAreRemoved(): void
    {
        self::assertSame(
            'HTTP 401: key [redacted] rejected; header [redacted] too',
            Redactor::redact('HTTP 401: key my-local-key-123 rejected; header hunter22 too', ['my-local-key-123', 'hunter22']),
        );
    }

    public function testKeyShapedTextIsRemovedEvenWhenNotOurs(): void
    {
        self::assertSame('Authorization: Bearer [redacted]', Redactor::redact('Authorization: Bearer abc.def-ghi'));
        self::assertSame('bad key [redacted]', Redactor::redact('bad key sk-proj-abcdefgh12345678'));
        self::assertSame('bad key [redacted]', Redactor::redact('bad key sk-ant-api03-abcdefgh12345678'));
        self::assertSame('see [redacted] here', Redactor::redact('see AIzaSyA1234567890abcdefghijklmn here'));
        self::assertSame('https://x.example/v1?key=[redacted]&a=1', Redactor::redact('https://x.example/v1?key=AIza123&a=1'));
        self::assertSame('Basic [redacted]', Redactor::redact('Basic dXNlcjpwYXNz'));
    }

    public function testShortSecretsAreNotUsedAsPatterns(): void
    {
        self::assertSame('a model answered', Redactor::redact('a model answered', ['a']));
    }
}
