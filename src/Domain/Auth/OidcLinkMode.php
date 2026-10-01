<?php

declare(strict_types=1);

namespace Logbook\Domain\Auth;

/**
 * How a provider account with no identity yet finds its Logbook user
 * (spec.md §7.9 `OIDC_LINK`): only by the user linking it while signed in,
 * or also by an equal username.
 */
enum OidcLinkMode: string
{
    case Explicit = 'explicit';
    case Username = 'username';
}
