<?php

declare(strict_types=1);

namespace Logbook\Service\GettingStarted;

use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\SettingRepository;

/**
 * Each user's *Set up your first vehicle* progress, kept as JSON in a
 * user-scoped settings row (spec.md §7.9). No row means not started.
 */
final readonly class GettingStartedStore
{
    public const string SETTING = 'getting_started';

    public function __construct(private SettingRepository $settings)
    {
    }

    public function load(int $userId): GettingStartedProgress
    {
        return GettingStartedProgress::fromStored($this->settings->find(self::SETTING, SettingScope::User, $userId)?->value);
    }

    public function save(int $userId, GettingStartedProgress $progress): void
    {
        $this->settings->save(self::SETTING, $progress->toStored(), SettingScope::User, $userId);
    }
}
