<?php

declare(strict_types=1);

namespace Logbook\Service\Sharing;

/**
 * Why a share or transfer was not made (spec.md §7.21); each is a message
 * key under `sharing.refused.`.
 */
enum ShareRefusal: string
{
    case UnknownUser = 'unknown_user';
    case Disabled = 'disabled';
    case Owner = 'owner';
    case AlreadyShared = 'already_shared';
}
