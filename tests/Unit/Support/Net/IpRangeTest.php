<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Net;

use InvalidArgumentException;
use Logbook\Support\Net\IpRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AUTH_PROXY_TRUSTED entries (spec.md §7.9 *Trust check*): IPv4 and IPv6
 * addresses and CIDR ranges, IPv4-mapped IPv6 read as IPv4.
 */
final class IpRangeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'one address' => ['10.0.0.5', '10.0.0.5', true];
        yield 'another address' => ['10.0.0.5', '10.0.0.6', false];
        yield 'in a /16' => ['172.18.0.0/16', '172.18.200.3', true];
        yield 'outside a /16' => ['172.18.0.0/16', '172.19.0.1', false];
        yield 'host bits in the range are ignored' => ['172.18.5.9/16', '172.18.0.1', true];
        yield 'a /0 is everything IPv4' => ['0.0.0.0/0', '203.0.113.9', true];
        yield 'an odd prefix' => ['10.0.0.0/25', '10.0.0.127', true];
        yield 'past an odd prefix' => ['10.0.0.0/25', '10.0.0.128', false];
        yield 'IPv6 in a /64' => ['fd00:1::/64', 'fd00:1::abcd', true];
        yield 'IPv6 outside it' => ['fd00:1::/64', 'fd00:2::1', false];
        yield 'IPv6 loopback' => ['::1', '::1', true];
        yield 'an IPv4-mapped peer' => ['10.0.0.0/24', '::ffff:10.0.0.9', true];
        yield 'an IPv4-mapped entry' => ['::ffff:10.0.0.0/120', '10.0.0.9', true];
        yield 'IPv4 never matches IPv6' => ['0.0.0.0/0', 'fd00::1', false];
        yield 'IPv6 /0 never matches IPv4' => ['::/0', '10.0.0.1', false];
        yield 'not an address' => ['10.0.0.0/8', 'proxy.local', false];
        yield 'empty' => ['10.0.0.0/8', '', false];
        yield 'a zone' => ['fe80::/10', 'fe80::1%eth0', false];
    }

    #[DataProvider('cases')]
    public function testContains(string $range, string $address, bool $expected): void
    {
        self::assertSame($expected, IpRange::parse($range)->contains($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'a host name' => ['proxy.local'];
        yield 'too long a prefix' => ['10.0.0.0/33'];
        yield 'too long an IPv6 prefix' => ['fd00::/129'];
        yield 'a negative prefix' => ['10.0.0.0/-1'];
        yield 'a netmask' => ['10.0.0.0/255.0.0.0'];
        yield 'a short IPv4-mapped range' => ['::ffff:0:0/80'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalid')]
    public function testInvalidEntriesAreRefused(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        IpRange::parse($value);
    }

    public function testItPrintsAsCidr(): void
    {
        self::assertSame('172.18.0.0/16', (string) IpRange::parse('172.18.5.9/16'));
        self::assertSame('10.0.0.5/32', (string) IpRange::parse('10.0.0.5'));
        self::assertSame('fd00:1::/64', (string) IpRange::parse('fd00:1::/64'));
    }
}
