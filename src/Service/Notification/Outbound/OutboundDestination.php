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

    private const array LINK_LOCAL = ['169.254.0.0/16', 'fe80::/10'];

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
     */
    public function check(string $url, bool $restricted): Destination
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($host) || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return Destination::refused('', null, Destination::INVALID);
        }
        $host = strtolower(trim($host, '[]'));
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
            // Each address on its own: a name with one public and one private address is not *Internet*.
            if (!$policy->allows(ConnectionLocator::classify($host, [$address], $thisHost))) {
                return Destination::refused($host, $location, Destination::BLOCKED);
            }
        }

        return Destination::allowed($host, $location, $addresses[0]);
    }
}
