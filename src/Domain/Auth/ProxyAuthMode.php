<?php

declare(strict_types=1);

namespace Logbook\Domain\Auth;

/**
 * Which header sign-in is configured (spec.md §7.9): none, a plain
 * username header from trusted addresses, or Authentik's signed JWT.
 */
enum ProxyAuthMode: string
{
    case Off = 'off';
    case Header = 'header';
    case Jwt = 'jwt';
}
