<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\SettingRepository;

/**
 * The owner's tyre thresholds, kept as a user-scoped row of the settings
 * table (spec.md §6 Setting, §7.17).
 */
final readonly class TyreSettingsStore
{
    private const string THRESHOLDS = 'tyres.thresholds';

    public function __construct(private SettingRepository $settings)
    {
    }

    public function thresholds(int $userId): TyreThresholds
    {
        return TyreThresholds::fromArray($this->settings->find(self::THRESHOLDS, SettingScope::User, $userId)?->value);
    }

    public function saveThresholds(int $userId, TyreThresholds $thresholds): void
    {
        $this->settings->save(self::THRESHOLDS, $thresholds->toArray(), SettingScope::User, $userId);
    }
}
