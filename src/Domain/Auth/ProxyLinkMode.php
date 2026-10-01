<?php

declare(strict_types=1);

namespace Logbook\Domain\Auth;

/**
 * How a proxy account with no identity yet finds its Logbook user (spec.md
 * §7.9 `AUTH_PROXY_LINK`): only by a signed-in user linking it, or also by
 * an equal username (the default: the proxy decides who someone is).
 */
enum ProxyLinkMode: string
{
    case Identity = 'identity';
    case Username = 'username';
}
