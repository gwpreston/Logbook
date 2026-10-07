<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Net;

use DateTimeImmutable;
use Logbook\Support\Net\CachingHostResolver;
use Logbook\Support\Net\HostResolver;
use Logbook\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

/**
 * A name is asked once a minute, a failure included, so a dead resolver
 * costs one lookup per name and pass (Phase 36.2).
 */
final class CachingHostResolverTest extends TestCase
{
    public function testEachNameIsAskedOnceAMinuteFailuresIncluded(): void
    {
        $inner = new class implements HostResolver {
            /** @var list<string> */
            public array $asked = [];

            public function resolve(string $host): array
            {
                $this->asked[] = $host;

                return $host === 'ntfy.test' ? ['203.0.113.10'] : [];
            }
        };
        $clock = new MutableClock(new DateTimeImmutable('2026-10-07T10:00:00Z'));
        $resolver = new CachingHostResolver($inner, $clock);

        self::assertSame(['203.0.113.10'], $resolver->resolve('ntfy.test'));
        self::assertSame(['203.0.113.10'], $resolver->resolve('NTFY.test'));
        $resolver->resolve('nowhere.test');
        $resolver->resolve('nowhere.test');
        self::assertSame(['ntfy.test', 'nowhere.test'], $inner->asked);

        $clock->set(new DateTimeImmutable('2026-10-07T10:01:00Z'));
        $resolver->resolve('ntfy.test');
        self::assertCount(3, $inner->asked, 'asked again after a minute');
    }
}
