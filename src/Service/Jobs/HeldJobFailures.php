<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Logbook\Domain\Setting\Setting;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\SettingRepository;

/**
 * An admin's failed-job alerts held through their quiet hours (spec.md
 * §7.11 *Held, not queued*, Phase 36.4): the user setting
 * `jobs.held_failures`, `{job: run id}`.
 *
 * Phase 37: taken by one run only, so two runs finishing together send
 * them once (#269); forgotten, unsent, when the admin is demoted (#266).
 */
final readonly class HeldJobFailures
{
    public const string NAME = 'jobs.held_failures';

    public function __construct(private SettingRepository $settings)
    {
    }

    /**
     * @return array<string, int> run ids by job
     */
    public function of(int $userId): array
    {
        return self::parse($this->settings->find(self::NAME, SettingScope::User, $userId));
    }

    public function hold(int $userId, string $job, int $runId): void
    {
        $held = $this->of($userId);
        $held[$job] = $runId;
        $this->settings->save(self::NAME, $held, SettingScope::User, $userId);
    }

    /**
     * Removes the held entries and returns them; empty when there are none
     * or another run removed them first. Removed before anything is sent,
     * so a channel that hangs or throws never sends them twice.
     *
     * @return array<string, int> run ids by job
     */
    public function take(int $userId): array
    {
        $setting = $this->settings->find(self::NAME, SettingScope::User, $userId);
        if ($setting === null || !$this->settings->deleteIfUnchanged($setting)) {
            return [];
        }

        return self::parse($setting);
    }

    public function forget(int $userId): void
    {
        $this->settings->delete(self::NAME, SettingScope::User, $userId);
    }

    /**
     * @return array<string, int>
     */
    private static function parse(?Setting $setting): array
    {
        $held = [];
        foreach (is_array($setting?->value) ? $setting->value : [] as $job => $id) {
            if (is_string($job) && is_int($id)) {
                $held[$job] = $id;
            }
        }

        return $held;
    }
}
