<?php

declare(strict_types=1);

namespace Logbook\Service\Access;

use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\User\User;

/**
 * The Phase 18.1 policy: the one owner runs the install.
 */
final readonly class SingleOwnerInstanceAccess implements InstanceAccess
{
    public function can(User $user, InstanceAbility $ability): bool
    {
        return true;
    }
}
