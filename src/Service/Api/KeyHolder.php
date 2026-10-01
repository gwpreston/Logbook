<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Api\ApiKey;
use Logbook\Domain\User\User;

/**
 * A live API key and the active user it belongs to.
 */
final readonly class KeyHolder
{
    public function __construct(
        public User $user,
        public ApiKey $key,
    ) {
    }
}
