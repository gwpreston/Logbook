<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

use RuntimeException;

/**
 * Single sign-on didn't work (spec.md §7.9). The message is the reason,
 * for the log only: the person sees a generic message.
 */
final class OidcFailure extends RuntimeException
{
}
