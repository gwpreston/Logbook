<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\SettingRepository;

/**
 * The owner's *Needs attention* thresholds, kept as a user-scoped row of
 * the settings table (spec.md §6 Setting, §7.24).
 */
final readonly class AttentionSettingsStore
{
    private const string THRESHOLDS = 'attention.thresholds';

    public function __construct(private SettingRepository $settings)
    {
    }

    public function thresholds(int $userId): AttentionThresholds
    {
        return AttentionThresholds::fromArray($this->settings->find(self::THRESHOLDS, SettingScope::User, $userId)?->value);
    }

    public function saveThresholds(int $userId, AttentionThresholds $thresholds): void
    {
        $this->settings->save(self::THRESHOLDS, $thresholds->toArray(), SettingScope::User, $userId);
    }
}
