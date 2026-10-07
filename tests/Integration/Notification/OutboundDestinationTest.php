<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Notification;

use DI\Container;
use Logbook\Domain\Ai\Location;
use Logbook\Domain\Notification\MemberDestinations;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Ai\ConnectionLocator;
use Logbook\Service\Notification\Outbound\Destination;
use Logbook\Service\Notification\Outbound\OutboundDestination;
use Logbook\Service\Notification\Outbound\OutboundHttp;
use Logbook\Support\Net\HostResolver;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\FakeHostResolver;
use Logbook\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Where members' channels may send (spec.md §7.11): every address a host
 * resolves to must be in an allowed class, link-local, unspecified and
 * reserved addresses are never allowed for a member, an admin is not
 * restricted, and the request connects to the address that was checked.
 */
final class OutboundDestinationTest extends AppTestCase
{
    private FakeHostResolver $dns;

    /**
     * @return iterable<string, array{string, list<string>, string|null, string|null, string|null}>
     *         URL, what its host resolves to, and the outcome under internet / network / server
     *         (null = allowed, else the refusal)
     */
    public static function matrix(): iterable
    {
        $b = Destination::BLOCKED;
        $l = Destination::LINK_LOCAL;
        yield 'loopback' => ['http://127.0.0.1:8080/', [], $b, $b, null];
        yield 'IPv6 loopback' => ['http://[::1]:8080/', [], $b, $b, null];
        yield 'localhost' => ['http://localhost/topic', ['127.0.0.1'], $b, $b, null];
        yield 'host.docker.internal' => ['http://host.docker.internal/', ['172.17.0.1'], $b, $b, null];
        yield 'RFC 1918' => ['http://192.168.1.20/topic', [], $b, null, null];
        yield 'RFC 1918 (10/8)' => ['http://10.1.2.3/topic', [], $b, null, null];
        yield 'ULA' => ['http://[fd00::5]/topic', [], $b, null, null];
        yield 'CGNAT (Tailscale)' => ['http://100.101.102.103/', [], $b, null, null];
        yield 'a .lan name' => ['http://ntfy.lan/garage', ['192.168.1.30'], $b, null, null];
        yield 'link-local' => ['http://169.254.169.254/latest/meta-data/', [], $l, $l, $l];
        yield 'IPv6 link-local' => ['http://[fe80::1]/', [], $l, $l, $l];
        yield 'a name pointing at the metadata service' => ['http://meta.example/', ['169.254.169.254'], $l, $l, $l];
        yield 'unspecified' => ['http://0.0.0.0:8080/', [], $l, $l, $l];
        yield 'IPv6 unspecified' => ['http://[::]:8080/', [], $l, $l, $l];
        yield 'multicast' => ['http://224.0.0.1/', [], $l, $l, $l];
        yield 'reserved' => ['http://240.0.0.1/', [], $l, $l, $l];
        yield 'broadcast' => ['http://255.255.255.255/', [], $l, $l, $l];
        yield 'NAT64 to loopback' => ['http://[64:ff9b::7f00:1]/', [], $l, $l, $l];
        yield '6to4' => ['http://[2002:7f00:1::]/', [], $l, $l, $l];
        yield 'a public address' => ['https://ntfy.sh/garage', ['159.203.148.75'], null, null, null];
        yield 'public IPv6' => ['https://ntfy6.example/x', ['2606:4700::6810:84e5'], null, null, null];
        yield 'public and private addresses' => ['https://both.example/', ['159.203.148.75', '10.0.0.5'], $b, null, null];
        yield 'public and loopback addresses' => ['https://sneaky.example/', ['159.203.148.75', '127.0.0.1'], $b, $b, null];
        yield 'IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/', [], $b, $b, null];
        yield 'IPv4-mapped metadata' => ['http://[::ffff:169.254.169.254]/', [], $l, $l, $l];
        yield 'IPv4-mapped private' => ['http://[::ffff:192.168.1.1]/', [], $b, null, null];
        $u = Destination::UNRESOLVED;
        yield 'does not resolve' => ['https://nowhere.example/', [], $u, $u, $u];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('matrix')]
    public function testThePolicyMatrixForAMember(
        string $url,
        array $addresses,
        ?string $internet,
        ?string $network,
        ?string $server,
    ): void {
        $app = $this->app();
        $host = parse_url($url, PHP_URL_HOST);
        self::assertIsString($host);
        if ($addresses !== []) {
            $this->dns->hosts[strtolower(trim($host, '[]'))] = $addresses;
        }
        $destinations = $this->service($app, OutboundDestination::class);

        $cases = [
            [MemberDestinations::Internet, $internet],
            [MemberDestinations::Network, $network],
            [MemberDestinations::Server, $server],
        ];
        foreach ($cases as [$policy, $expected]) {
            $destinations->savePolicy($policy);
            $checked = $destinations->check($url, true);
            self::assertSame($expected, $checked->refusal, $policy->value . ': ' . $url);
            if ($expected === null) {
                self::assertNotNull($checked->address, 'an allowed member\'s request is pinned');
            }
        }

        // An admin's own channel is never restricted (but still classed for the badge).
        $admin = $destinations->check($url, false);
        self::assertTrue($admin->isAllowed(), 'admin: ' . $url);
        self::assertNull($admin->address, 'an admin\'s request resolves as usual');
    }

    public function testTheDefaultIsTheInternetAndYourNetwork(): void
    {
        $app = $this->app();

        $destinations = $this->service($app, OutboundDestination::class);
        self::assertSame('network', $destinations->policy()->value);
        $this->service($app, SettingRepository::class)->save(OutboundDestination::SETTING, 'nonsense');
        self::assertSame('network', $destinations->policy()->value, 'an unknown value: the default');
    }

    public function testAnAdminsThisServerAddressesCountAsThisServer(): void
    {
        $app = $this->app();
        $this->service($app, SettingRepository::class)->save(ConnectionLocator::THIS_HOST, ['203.0.113.7']);
        $destinations = $this->service($app, OutboundDestination::class);

        $destinations->savePolicy(MemberDestinations::Network);
        self::assertSame(Destination::BLOCKED, $destinations->check('http://203.0.113.7/topic', true)->refusal);
        self::assertSame(Location::Server, $destinations->check('http://203.0.113.7/topic', false)->location);
    }

    public function testTheRequestIsPinnedToTheCheckedAddressAndNeverFollowsARedirect(): void
    {
        $app = $this->app();
        $http = new RecordingHttpClient();
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(HttpClientInterface::class, $http->client);
        // A name that answers differently each time (DNS rebinding): public, then private.
        $rebinding = new class implements HostResolver {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                return ++$this->calls === 1 ? ['159.203.148.75', '2606:4700::1'] : ['127.0.0.1'];
            }
        };
        $container->set(HostResolver::class, $rebinding);
        $this->service($app, OutboundDestination::class)->savePolicy(MemberDestinations::Internet);
        $outbound = $this->service($app, OutboundHttp::class);

        $result = $outbound->post('ntfy', 'https://rebind.example/', ['json' => ['x' => 1]], true);

        self::assertTrue($result->delivered);
        self::assertSame(1, $rebinding->calls, 'resolved once, for the check');
        self::assertSame(['rebind.example' => '159.203.148.75'], $http->requests[0]['resolve'], 'the checked IPv4 address');
        self::assertSame(0, $http->requests[0]['max_redirects']);

        // The next send checks again: the name now points at this server, so nothing is sent.
        $refused = $outbound->post('ntfy', 'https://rebind.example/', [], true);
        self::assertFalse($refused->delivered);
        self::assertStringContainsString('not an address your administrator allows', (string) $refused->error);
        self::assertCount(1, $http->requests);

        // A redirect is reported, not followed.
        $http->status = 302;
        $this->dns->hosts['moved.example'] = ['159.203.148.75'];
        $destinations = new OutboundDestination(
            $this->dns,
            $this->service($app, SettingRepository::class),
            $this->service($app, ConnectionLocator::class),
        );
        $moved = (new OutboundHttp($http->client, $destinations))->post('ntfy', 'https://moved.example/', [], true);
        self::assertFalse($moved->delivered);
        self::assertSame('HTTP 302: a redirect, which is not followed', $moved->error);
    }

    /**
     * @return App<ContainerInterface>
     */
    private function app(): App
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->dns = new FakeHostResolver();
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(HostResolver::class, $this->dns);

        return $app;
    }
}
