<?php

declare(strict_types=1);

namespace Logbook\Support\Net;

use InvalidArgumentException;

/**
 * One IPv4 or IPv6 address or CIDR range (spec.md §7.9
 * `AUTH_PROXY_TRUSTED`), parsed once at boot. An IPv4-mapped IPv6 address
 * (`::ffff:10.0.0.5`, as dual-stack listeners report IPv4 peers) is read
 * as its IPv4 form, on both sides.
 */
final readonly class IpRange
{
    private const string MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    private function __construct(
        /** The network address in binary (4 or 16 bytes), host bits zeroed. */
        private string $network,
        private int $prefix,
    ) {
    }

    /**
     * "10.0.0.5", "172.18.0.0/16", "fd00::/8", "::1".
     *
     * @throws InvalidArgumentException when it is neither
     */
    public static function parse(string $value): self
    {
        $value = trim($value);
        $address = $value;
        $prefix = null;
        if (str_contains($value, '/')) {
            [$address, $bits] = explode('/', $value, 2);
            if (preg_match('/^\d{1,3}$/', $bits) !== 1) {
                throw new InvalidArgumentException(sprintf('"%s" is not an IP address or CIDR range.', $value));
            }
            $prefix = (int) $bits;
        }
        $binary = self::binary($address);
        if ($binary === null) {
            throw new InvalidArgumentException(sprintf('"%s" is not an IP address or CIDR range.', $value));
        }
        $wasMapped = strlen($binary) === 16 && str_starts_with($binary, self::MAPPED_PREFIX);
        if ($wasMapped) {
            $binary = substr($binary, 12);
            if ($prefix !== null) {
                if ($prefix < 96) {
                    throw new InvalidArgumentException(sprintf(
                        '"%s" is an IPv4-mapped range shorter than /96; write the IPv4 range instead.',
                        $value,
                    ));
                }
                $prefix -= 96;
            }
        }
        $max = strlen($binary) * 8;
        $prefix ??= $max;
        if ($prefix > $max) {
            throw new InvalidArgumentException(sprintf('"%s" has a prefix longer than /%d.', $value, $max));
        }

        return new self(self::mask($binary, $prefix), $prefix);
    }

    /**
     * Whether $address (as in REMOTE_ADDR) is in this range; false for
     * anything that is not an address.
     */
    public function contains(string $address): bool
    {
        $binary = self::binary(trim($address));
        if ($binary === null) {
            return false;
        }
        if (strlen($binary) === 16 && str_starts_with($binary, self::MAPPED_PREFIX)) {
            $binary = substr($binary, 12);
        }

        return strlen($binary) === strlen($this->network) && self::mask($binary, $this->prefix) === $this->network;
    }

    /**
     * @param list<self> $ranges
     */
    public static function anyContains(array $ranges, string $address): bool
    {
        foreach ($ranges as $range) {
            if ($range->contains($address)) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        $text = inet_ntop($this->network);

        return ($text === false ? '?' : $text) . '/' . $this->prefix;
    }

    private static function binary(string $address): ?string
    {
        // inet_pton() accepts only plain addresses; a zone ("fe80::1%eth0") is not one.
        if ($address === '' || str_contains($address, '%')) {
            return null;
        }
        $binary = @inet_pton($address);

        return $binary === false ? null : $binary;
    }

    private static function mask(string $binary, int $prefix): string
    {
        $out = '';
        $length = strlen($binary);
        for ($i = 0; $i < $length; $i++) {
            $bits = max(0, min(8, $prefix - $i * 8));
            $byteMask = $bits === 0 ? 0 : (0xff << (8 - $bits)) & 0xff;
            $out .= chr(ord($binary[$i]) & $byteMask);
        }

        return $out;
    }
}
