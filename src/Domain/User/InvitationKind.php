<?php

declare(strict_types=1);

namespace Logbook\Domain\User;

/**
 * What a one-time link does (spec.md §6 Invitation): create a new account,
 * set a new password for an existing one (an admin's reset), or sign an
 * existing user in (the command line's break-glass link, Phase 23.1), or
 * confirm an email address (Phase 33.1).
 */
enum InvitationKind: string
{
    case Invite = 'invite';
    case Reset = 'reset';
    case Login = 'login';
    /** Confirms a new email address (Phase 33.1); the address is on the link. */
    case Email = 'email';
}
