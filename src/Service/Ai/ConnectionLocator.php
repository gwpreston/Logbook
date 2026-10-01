<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use InvalidArgumentException;
use Logbook\Domain\Ai\Location;
use Logbook\Repository\SettingRepository;
use Logbook\Support\Net\HostResolver;
use Logbook\Support\Net\IpRange;

/**
 * Classes a connection's host as *This server*, *Your network* or
 * *Internet* (spec.md §7.25 *Where it runs*), by what it resolves to, not
 * what it looks like. A name with several addresses takes the widest
 * class; one that does not resolve is classed by its name alone.
 */
final readonly class ConnectionLocator
{
    /** Global setting: the addresses an admin marks as this server. */
    public const string THIS_HOST = 'ai.this_host';

    /** Names that are this server (Docker's host aliases). */
    private const array SERVER_NAMES = ['localhost', 'host.docker.internal', 'host-gateway'];

    /** Name suffixes of a home or office network. */
    private const array NETWORK_SUFFIXES = ['.local', '.lan', '.internal', '.home.arpa'];

    private const array LOOPBACK = ['127.0.0.0/8', '::1/128'];

    /** RFC 1918, link-local, IPv6 ULA, and the shared range Tailscale uses (#69). */
    private const array PRIVATE = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        'fe80::/10',
        'fc00::/7',
        '100.64.0.0/10',
    ];

    public function __construct(
        private HostResolver $resolver,
        private SettingRepository $settings,
    ) {
    }

    public function locate(string $url): Located
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? strtolower(trim($host, '[]')) : '';
        $addresses = $host === '' ? [] : $this->resolver->resolve($host);
        $thisHost = $this->thisHost();

        return new Located(self::classify($host, $addresses, $thisHost), $host, $addresses);
    }

    /**
     * @param list<string> $addresses
     * @param list<string> $thisHost addresses, ranges or names the admin marked as this server
     */
    public static function classify(string $host, array $addresses, array $thisHost = []): Location
    {
        $host = strtolower(rtrim($host, '.'));
        $named = in_array($host, self::SERVER_NAMES, true) || in_array($host, $thisHost, true);

        if ($addresses === []) {
            return match (true) {
                $named => Location::Server,
                filter_var($host, FILTER_VALIDATE_IP) !== false => self::ofAddress($host, $thisHost),
                self::looksLocal($host) => Location::Network,
                default => Location::Internet,
            };
        }

        $location = Location::Server;
        foreach ($addresses as $address) {
            $location = $location->widest(self::ofAddress($address, $thisHost));
        }

        // Docker's host aliases point at a bridge or LAN address that is this server.
        return $named && $location !== Location::Internet ? Location::Server : $location;
    }

    /**
     * @return list<string>
     */
    public function thisHost(): array
    {
        $value = $this->settings->find(self::THIS_HOST)?->value;
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? strtolower(trim($item)) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param list<string> $thisHost
     */
    private static function ofAddress(string $address, array $thisHost): Location
    {
        if (self::inAny(self::LOOPBACK, $address) || self::inAny($thisHost, $address)) {
            return Location::Server;
        }

        return self::inAny(self::PRIVATE, $address) ? Location::Network : Location::Internet;
    }

    private static function looksLocal(string $host): bool
    {
        if (!str_contains($host, '.')) {
            return true;
        }
        foreach (self::NETWORK_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $ranges addresses or CIDR ranges; anything else is skipped
     */
    private static function inAny(array $ranges, string $address): bool
    {
        foreach ($ranges as $range) {
            try {
                if (IpRange::parse($range)->contains($address)) {
                    return true;
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return false;
    }
}
