<?php

declare(strict_types=1);

namespace Logbook\Support\Net;

/**
 * Resolves a host name to its addresses (IPv4 and IPv6). An interface so
 * classing can be tested without DNS.
 */
interface HostResolver
{
    /**
     * @return list<string> every address, empty when the name does not resolve
     */
    public function resolve(string $host): array;
}
