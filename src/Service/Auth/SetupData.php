<?php

declare(strict_types=1);

namespace Logbook\Service\Auth;

use Logbook\Support\Display\DisplayPreferences;
use SensitiveParameter;

final readonly class SetupData
{
    public function __construct(
        public string $username,
        #[SensitiveParameter] public string $password,
        public string $displayName,
        public DisplayPreferences $preferences,
    ) {
    }
}
