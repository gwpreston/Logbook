<?php

declare(strict_types=1);

namespace Logbook\Support\Net;

/**
 * The system resolver: IPv4 through `gethostbynamel()` (which reads
 * /etc/hosts, so `host.docker.internal` resolves inside Docker) and IPv6
 * AAAA records through `dns_get_record()`.
 */
final readonly class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host);
        $addresses = $addresses === false ? [] : $addresses;
        if (function_exists('dns_get_record') && str_contains($host, '.')) {
            $records = @dns_get_record($host, DNS_AAAA);
            foreach (is_array($records) ? $records : [] as $record) {
                if (is_string($record['ipv6'] ?? null)) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
