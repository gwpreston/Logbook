<?php

declare(strict_types=1);

namespace Logbook\Service\Access;

use Logbook\Domain\User\User;

/**
 * The signed-in user whose access templates ask about. Like DisplayContext,
 * set once per request (by CurrentUserMiddleware), so macros can call
 * can_see_costs() without being handed the user.
 */
final class AccessContext
{
    private ?User $user = null;

    public function user(): ?User
    {
        return $this->user;
    }

    public function apply(?User $user): void
    {
        $this->user = $user;
    }
}
