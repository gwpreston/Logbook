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
    /** How long Ask Logbook keeps a thread after its last message (spec.md §7.26). */
    public const string RETENTION = 'ai.ask_retention_days';
    public const array RETENTION_CHOICES = [1, 7, 30, 90];
    public const int RETENTION_DEFAULT = 30;

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

    public function retentionDays(int $userId): int
    {
        $value = $this->settings->find(self::RETENTION, SettingScope::User, $userId)?->value;

        return is_int($value) && in_array($value, self::RETENTION_CHOICES, true) ? $value : self::RETENTION_DEFAULT;
    }

    public function setRetentionDays(int $userId, int $days): void
    {
        if (in_array($days, self::RETENTION_CHOICES, true)) {
            $this->settings->save(self::RETENTION, $days, SettingScope::User, $userId);
        }
    }
}
