<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\User\User;
use Logbook\Service\Access\InstanceAccess;

/**
 * In an active demo the visitor is an admin who may switch modules and
 * nothing else install-wide (spec.md §7.36): no users, backup, restore,
 * AI, jobs or fuel price providers. The settings page and the navigation
 * ask this, so the links are simply not there; the routes themselves are
 * refused by DemoGuardMiddleware.
 */
final readonly class DemoInstanceAccess implements InstanceAccess
{
    public function __construct(
        private InstanceAccess $inner,
        private DemoMode $mode,
    ) {
    }

    public function can(User $user, InstanceAbility $ability): bool
    {
        if ($ability !== InstanceAbility::ManageModules && $this->mode->blocks(DemoRestriction::Administration)) {
            return false;
        }

        return $this->inner->can($user, $ability);
    }
}
