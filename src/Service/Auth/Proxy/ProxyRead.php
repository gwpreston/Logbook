<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Proxy;

use Logbook\Service\Auth\External\ExternalAccount;

/**
 * What one request's proxy headers say (spec.md §7.9): the account they
 * vouch for, when every check passed, and whether the request came from a
 * listed proxy (a missing header is then the proxy's mistake).
 */
final readonly class ProxyRead
{
    public function __construct(
        public ?ExternalAccount $account = null,
        public bool $fromListedProxy = false,
    ) {
    }

    /**
     * The identity key a header-based session remembers.
     */
    public function key(): ?string
    {
        return $this->account === null ? null : self::keyOf($this->account);
    }

    public static function keyOf(ExternalAccount $account): string
    {
        return $account->issuer . "\n" . $account->subject;
    }
}
