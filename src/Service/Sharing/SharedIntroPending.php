<?php

declare(strict_types=1);

namespace Logbook\Service\Sharing;

use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\SettingRepository;

/**
 * The vehicles whose share a user has yet to be introduced to (spec.md §7.9
 * *A shared user's first visit*): a list of vehicle ids in a user-scoped
 * settings row. A share is marked when it is created, so shares that exist
 * already never show the introduction.
 */
final readonly class SharedIntroPending
{
    public const string SETTING = 'shared_intro';

    public function __construct(private SettingRepository $settings)
    {
    }

    public function mark(int $userId, int $vehicleId): void
    {
        $pending = $this->ids($userId);
        if (!in_array($vehicleId, $pending, true)) {
            $this->store($userId, [...$pending, $vehicleId]);
        }
    }

    /**
     * Seen, or the share is gone.
     */
    public function clear(int $userId, int $vehicleId): void
    {
        $pending = $this->ids($userId);
        if (in_array($vehicleId, $pending, true)) {
            $this->store($userId, array_values(array_diff($pending, [$vehicleId])));
        }
    }

    /**
     * @return list<int>
     */
    public function ids(int $userId): array
    {
        $value = $this->settings->find(self::SETTING, SettingScope::User, $userId)?->value;

        return is_array($value) ? array_values(array_filter($value, is_int(...))) : [];
    }

    /**
     * @param list<int> $ids
     */
    private function store(int $userId, array $ids): void
    {
        if ($ids === []) {
            $this->settings->delete(self::SETTING, SettingScope::User, $userId);

            return;
        }
        $this->settings->save(self::SETTING, $ids, SettingScope::User, $userId);
    }
}
