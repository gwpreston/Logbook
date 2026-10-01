<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Logbook\Domain\Attention\AttentionKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AttentionHiddenRepository;
use Psr\Clock\ClockInterface;

/**
 * *Hide* on a data check (spec.md §7.24 *Hiding*). The item is judged
 * again here: it is hidden only when it is still on this user's list, may
 * be hidden by them, and has the fingerprint the page showed, so a click
 * never hides a state the user did not see. The stored fingerprint is
 * always the one computed here.
 */
final readonly class AttentionHiding
{
    public function __construct(
        private AttentionList $list,
        private AttentionHiddenRepository $hidden,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether it was hidden (false: it changed, or there is nothing to hide)
     */
    public function hide(User $user, Vehicle $vehicle, string $kind, int $subjectId, string $fingerprint): bool
    {
        $kind = AttentionKind::hideable($kind);
        if ($kind === null) {
            return false;
        }

        foreach ($this->list->forVehicles($user, [$vehicle], sync: false, withHidden: true)->items as $item) {
            if (
                $item->kind === $kind
                && $item->subjectId === $subjectId
                && $item->canHide
                && $item->fingerprint !== null
                && hash_equals($item->fingerprint, $fingerprint)
            ) {
                $this->hidden->hide($user->id, $vehicle->id, $kind, $subjectId, $item->fingerprint, $this->clock->now());

                return true;
            }
        }

        return false;
    }
}
