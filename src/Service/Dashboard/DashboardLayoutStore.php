<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\SettingRepository;

/**
 * Each owner's dashboard layout, kept as JSON in a user-scoped settings row
 * (spec.md §7.8). No row means the default layout.
 */
final readonly class DashboardLayoutStore
{
    public const string SETTING = 'dashboard.layout';

    public function __construct(private SettingRepository $settings)
    {
    }

    public function load(int $userId): DashboardLayout
    {
        return DashboardLayout::fromArray($this->settings->find(self::SETTING, SettingScope::User, $userId)?->value);
    }

    public function save(int $userId, DashboardLayout $layout): void
    {
        if ($layout->isDefault()) {
            $this->reset($userId);

            return;
        }

        $this->settings->save(self::SETTING, $layout->toArray(), SettingScope::User, $userId);
    }

    /**
     * A new user starts on the *Household* layout (spec.md §7.8). Existing
     * users have no row and keep the full layout.
     */
    public function startNewUser(int $userId): void
    {
        $this->settings->save(self::SETTING, DashboardLayout::household()->toArray(), SettingScope::User, $userId);
    }

    public function reset(int $userId): void
    {
        $this->settings->delete(self::SETTING, SettingScope::User, $userId);
    }
}
