<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

/**
 * How a callback ended (spec.md §7.9).
 */
enum OidcOutcome: string
{
    /** An existing user, found by identity or username. */
    case SignedIn = 'signed_in';
    /** A new member created on first sign-in: show the welcome form. */
    case Created = 'created';
    /** The signed-in user linked their provider account. */
    case Linked = 'linked';
    /** Any validation or provider failure; the reason is logged only. */
    case Failed = 'failed';
    /** A valid provider account no Logbook user may use (step 4, or outside the allowed groups). */
    case NotLinked = 'not_linked';
    /** Linking refused: the provider account belongs to someone else. */
    case LinkTaken = 'link_taken';
    /** Linking refused: this user already has a provider account linked. */
    case AlreadyLinked = 'already_linked';
}
