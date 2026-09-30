<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\User\User;
use Logbook\Repository\SettingRepository;

/**
 * Reads and writes a user's `trips` setting (spec.md §6 *Trip settings*).
 */
final readonly class TripSettingsStore
{
    public const string KEY = 'trips';

    public function __construct(private SettingRepository $settings)
    {
    }

    public function for(User $user): TripSettings
    {
        return TripSettings::fromArray(
            $this->settings->find(self::KEY, SettingScope::User, $user->id)?->value,
            $user->preferences->locale,
        );
    }

    public function save(User $user, TripSettings $settings): void
    {
        $this->settings->save(self::KEY, $settings->toArray(), SettingScope::User, $user->id);
    }
}
