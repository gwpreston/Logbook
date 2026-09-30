<?php

declare(strict_types=1);

namespace Logbook\Domain\User;

/**
 * What a one-time link does (spec.md §6 Invitation): create a new account,
 * or set a new password for an existing one (an admin's reset).
 */
enum InvitationKind: string
{
    case Invite = 'invite';
    case Reset = 'reset';
}
