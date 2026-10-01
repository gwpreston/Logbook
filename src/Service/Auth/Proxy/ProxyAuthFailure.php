<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Proxy;

use RuntimeException;

/**
 * A proxy's header was refused (spec.md §7.9). The message is the reason,
 * for the log only: the request just goes on as if no header was sent.
 */
final class ProxyAuthFailure extends RuntimeException
{
}
