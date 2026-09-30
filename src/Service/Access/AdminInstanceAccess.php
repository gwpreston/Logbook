<?php

declare(strict_types=1);

namespace Logbook\Service\Access;

use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\User\User;

/**
 * The Phase 19 policy: admins run the install (spec.md §7.9); members
 * have their own settings only.
 */
final readonly class AdminInstanceAccess implements InstanceAccess
{
    public function can(User $user, InstanceAbility $ability): bool
    {
        return $user->isAdmin && $user->isActive();
    }
}
