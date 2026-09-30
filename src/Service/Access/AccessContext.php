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

    /**
     * Who is adding an entry now, for its `created_by` (Phase 19): the
     * signed-in user or the API key's; null on the command line and in
     * seeds, which reads as the vehicle's owner.
     */
    public function authorId(): ?int
    {
        return $this->user?->id;
    }

    public function apply(?User $user): void
    {
        $this->user = $user;
    }
}
