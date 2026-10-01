<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

/**
 * Why the flow was started: to sign in, or (signed in already) to link the
 * provider account to this user (spec.md §7.9 *Linking*).
 */
enum OidcPurpose: string
{
    case SignIn = 'sign_in';
    case Link = 'link';
}
