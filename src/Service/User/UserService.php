<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use Logbook\Domain\User\User;
use Logbook\Repository\UserRepository;
use Logbook\Support\Display\Theme;
use Psr\Clock\ClockInterface;

/**
 * Profile and display preferences of an account.
 */
final readonly class UserService
{
    public function __construct(
        private UserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    public function updateProfile(User $user, ProfileData $profile): void
    {
        $this->users->updateProfile($user->id, $profile->displayName, $profile->preferences, $this->clock->now());
    }

    public function setTheme(User $user, Theme $theme): void
    {
        $this->users->updateTheme($user->id, $theme, $this->clock->now());
    }
}
