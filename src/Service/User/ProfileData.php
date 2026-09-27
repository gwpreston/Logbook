<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use Logbook\Support\Display\DisplayPreferences;

final readonly class ProfileData
{
    public function __construct(
        public string $displayName,
        public DisplayPreferences $preferences,
    ) {
    }
}
