<?php

declare(strict_types=1);

namespace Logbook\Domain\Setting;

enum SettingScope: string
{
    /** Instance-wide (feature toggles, defaults). owner_id is always 0. */
    case Global = 'global';

    /** Per user (preferences, dashboard layout). owner_id is the user id. */
    case User = 'user';
}
