<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Outbound;

use Logbook\Domain\Ai\Location;
use Logbook\Domain\Notification\MemberDestinations;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Ai\ConnectionLocator;
use Logbook\Support\Net\HostResolver;
use Logbook\Support\Net\IpRange;

/**
 * Checks where a personal channel may send (spec.md §7.11 *Where members'
 * channels may send*, Phase 36.2). For a member, **every** address the
 * host resolves to must be in a class the admin allows, none may be
 * link-local, and the request then connects to one of those addresses, so
 * the name cannot change between the check and the call. An admin's own
 * channels are not restricted; their host is still classed for the badge.
 */
final readonly class OutboundDestination
{
    /** Global setting: what members' channels may reach. */
    public const string SETTING = 'notifications.member_destinations';

    /** Cloud metadata services and router interfaces: never for members. */
    private const array LINK_LOCAL = ['169.254.0.0/16', 'fe80::/10'];

    /**
     * Never for members either: "this host" (0.0.0.0 reaches the server's
     * own services on Linux), multicast, reserved and broadcast, and IPv6
     * forms that carry an IPv4 address the classes could not see
     * (IPv4-compatible, NAT64, 6to4).
     */
    private const array NEVER = [
        '0.0.0.0/8',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/96',
        'ff00::/8',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '2002::/16',
    ];

    public function __construct(
        private HostResolver $resolver,
        private SettingRepository $settings,
        private ConnectionLocator $locator,
    ) {
    }

    public function policy(): MemberDestinations
    {
        return MemberDestinations::fromStored($this->settings->find(self::SETTING)?->value);
    }

    public function savePolicy(MemberDestinations $policy): void
    {
        $this->settings->save(self::SETTING, $policy->value);
    }

    /**
     * @param bool $restricted a member's channel (the policy applies)
     * @param bool $classify for an unrestricted channel, resolve the host for the badge
     */
    public function check(string $url, bool $restricted, bool $classify = true): Destination
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($host) || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return Destination::refused('', null, Destination::INVALID);
        }
        $host = strtolower(trim($host, '[]'));
        if (!$restricted && !$classify) {
            return Destination::allowed($host, null, null);
        }
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $literal ? [$host] : $this->resolver->resolve($host);
        $thisHost = $this->locator->thisHost();
        $location = ConnectionLocator::classify($host, $literal ? [] : $addresses, $thisHost);

        if (!$restricted) {
            return Destination::allowed($host, $location, null);
        }
        if ($addresses === []) {
            return Destination::refused($host, null, Destination::UNRESOLVED);
        }

        $policy = $this->policy();
        foreach ($addresses as $address) {
            if (IpRange::anyContains(array_map(IpRange::parse(...), self::LINK_LOCAL), $address)) {
                return Destination::refused($host, $location, Destination::LINK_LOCAL);
            }
            // ::1 is in ::/96 but is loopback, which the policy decides.
            if ($address !== '::1' && IpRange::anyContains(array_map(IpRange::parse(...), self::NEVER), $address)) {
                return Destination::refused($host, $location, Destination::LINK_LOCAL);
            }
            // Each address on its own: a name with one public and one private address is not *Internet*.
            if (!$policy->allows(ConnectionLocator::classify($host, [$address], $thisHost))) {
                return Destination::refused($host, $location, Destination::BLOCKED);
            }
        }

        // Every address passed; connect to one of them, an IPv4 one where there is one.
        $ipv4 = array_values(array_filter(
            $addresses,
            static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
        ));

        return Destination::allowed($host, $location, $ipv4[0] ?? $addresses[0]);
    }
}
