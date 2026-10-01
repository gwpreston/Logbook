<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\SettingRepository;

/**
 * A user's *Use AI features* switch (spec.md §7.25 *Users*, decided #67):
 * on unless they switched it off.
 */
final readonly class AiPreferences
{
    public const string SETTING = 'ai.use';

    public function __construct(private SettingRepository $settings)
    {
    }

    public function isOn(int $userId): bool
    {
        return $this->settings->find(self::SETTING, SettingScope::User, $userId)?->value !== false;
    }

    public function set(int $userId, bool $on): void
    {
        $this->settings->save(self::SETTING, $on, SettingScope::User, $userId);
    }
}
