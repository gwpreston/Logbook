<?php

declare(strict_types=1);

namespace Logbook\Service\Sharing;

use Logbook\Domain\Access\VehicleShare;
use Logbook\Domain\User\User;

/**
 * One share on the Sharing page: the share and the person it is for.
 */
final readonly class ShareRow
{
    public function __construct(
        public VehicleShare $share,
        public User $user,
    ) {
    }
}
