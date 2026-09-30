<?php

declare(strict_types=1);

namespace Logbook\Service\Access;

use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\User\User;

/**
 * Who may change install-wide settings (spec.md §5 *Access policy*).
 */
interface InstanceAccess
{
    public function can(User $user, InstanceAbility $ability): bool;
}
