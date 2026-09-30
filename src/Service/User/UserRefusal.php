<?php

declare(strict_types=1);

namespace Logbook\Service\User;

/**
 * Why an admin's change to a user was refused (spec.md §7.9); each is a
 * message key under `users.refused.`.
 */
enum UserRefusal: string
{
    case LastAdmin = 'last_admin';
    case Yourself = 'yourself';
    case OwnsVehicles = 'owns_vehicles';
}
