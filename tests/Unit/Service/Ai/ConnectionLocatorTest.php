<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai;

use Logbook\Domain\Ai\Location;
use Logbook\Service\Ai\ConnectionLocator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where a connection runs (spec.md §7.25 *Where it runs*): by what the
 * host resolves to, the widest class winning, names only when it does not
 * resolve.
 */
final class ConnectionLocatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>, Location}>
     */
    public static function hosts(): iterable
    {
        yield 'IPv4 loopback' => ['127.0.0.1', ['127.0.0.1'], Location::Server];
        yield 'IPv6 loopback' => ['::1', ['::1'], Location::Server];
        yield 'localhost' => ['localhost', ['127.0.0.1', '::1'], Location::Server];
        yield 'host.docker.internal on the bridge' => ['host.docker.internal', ['172.17.0.1'], Location::Server];
        yield 'host.docker.internal on Docker Desktop' => ['host.docker.internal', ['192.168.65.254'], Location::Server];
        yield 'host-gateway unresolved' => ['host-gateway', [], Location::Server];
        yield 'RFC 1918 10/8' => ['10.1.2.3', ['10.1.2.3'], Location::Network];
        yield 'RFC 1918 172.16/12' => ['172.31.0.9', ['172.31.0.9'], Location::Network];
        yield 'RFC 1918 192.168/16' => ['192.168.1.20', ['192.168.1.20'], Location::Network];
        yield 'just outside 172.16/12' => ['172.32.0.1', ['172.32.0.1'], Location::Internet];
        yield 'link-local' => ['169.254.10.10', ['169.254.10.10'], Location::Network];
        yield 'IPv6 link-local' => ['fe80::1', ['fe80::1'], Location::Network];
        yield 'IPv6 ULA' => ['fd12:3456::1', ['fd12:3456::1'], Location::Network];
        yield 'Tailscale (#69)' => ['gpu.tail1234.ts.net', ['100.101.102.103'], Location::Network];
        yield 'Tailscale IPv6' => ['gpu.tail1234.ts.net', ['fd7a:115c:a1e0::1'], Location::Network];
        yield '.local resolved privately' => ['desktop.local', ['192.168.1.20'], Location::Network];
        yield '.lan unresolved' => ['gpu.lan', [], Location::Network];
        yield '.home.arpa unresolved' => ['gpu.home.arpa', [], Location::Network];
        yield 'a bare machine name unresolved' => ['desktop', [], Location::Network];
        yield 'public address' => ['api.openai.com', ['104.18.7.192'], Location::Internet];
        yield 'public IPv6' => ['api.anthropic.com', ['2607:6bc0::10'], Location::Internet];
        yield 'public name, private address' => ['ollama.example.com', ['192.168.1.20'], Location::Network];
        yield 'private and public: the widest' => ['split.example.com', ['10.0.0.5', '203.0.113.9'], Location::Internet];
        yield 'loopback and private: the widest' => ['both.example.com', ['127.0.0.1', '10.0.0.5'], Location::Network];
        yield 'IPv4-mapped private' => ['mapped.example.com', ['::ffff:192.168.1.20'], Location::Network];
        yield 'IPv4-mapped public' => ['mapped.example.com', ['::ffff:8.8.8.8'], Location::Internet];
        yield 'public name unresolved' => ['nowhere.example.com', [], Location::Internet];
        yield 'a .local name resolving publicly' => ['trick.local', ['203.0.113.9'], Location::Internet];
        yield 'host.docker.internal resolving publicly' => ['host.docker.internal', ['203.0.113.9'], Location::Internet];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('hosts')]
    public function testHostsAreClassedByWhatTheyResolveTo(string $host, array $addresses, Location $expected): void
    {
        self::assertSame($expected, ConnectionLocator::classify($host, $addresses));
    }

    public function testAnAddressTheAdminMarksIsThisServer(): void
    {
        self::assertSame(Location::Network, ConnectionLocator::classify('ollama', ['172.18.0.5']));
        self::assertSame(Location::Server, ConnectionLocator::classify('ollama', ['172.18.0.5'], ['172.18.0.0/16']));
        self::assertSame(Location::Server, ConnectionLocator::classify('192.168.1.5', ['192.168.1.5'], ['192.168.1.5']));
        self::assertSame(Location::Server, ConnectionLocator::classify('ollama', [], ['ollama']));
    }

    public function testMarkingNeverMakesAPublicAddressThisServerByName(): void
    {
        self::assertSame(
            Location::Internet,
            ConnectionLocator::classify('ollama.example.com', ['203.0.113.9'], ['ollama.example.com']),
        );
    }
}
