<?php

declare(strict_types=1);

namespace Logbook\Service\Updates;

use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\User\User;
use Logbook\Repository\SettingRepository;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Version\SemVer;

/**
 * The update check's settings (spec.md §6 Setting `updates.*`, §7.31):
 * whether it is on (off by default, and never with
 * `UPDATE_CHECK_ALLOWED=false`), the banner, the install's daily minute,
 * the last result and each admin's dismissed version.
 */
final readonly class UpdateSettings
{
    public const string CHECK = 'updates.check';
    public const string BANNER = 'updates.banner';
    public const string MINUTE = 'updates.minute';
    public const string STATUS = 'updates.status';
    public const string DISMISSED = 'updates.dismissed';

    public function __construct(
        private SettingRepository $settings,
        private AppSettings $app,
    ) {
    }

    /** `UPDATE_CHECK_ALLOWED`: false removes the option entirely. */
    public function allowed(): bool
    {
        return $this->app->updateCheckAllowed;
    }

    public function repository(): string
    {
        return $this->app->updateCheckRepo;
    }

    /** *Check for updates*: on only when allowed and switched on. */
    public function checking(): bool
    {
        return $this->allowed() && $this->settings->find(self::CHECK)?->value === true;
    }

    /** *Show update banner*: on unless switched off. */
    public function banner(): bool
    {
        return $this->settings->find(self::BANNER)?->value !== false;
    }

    public function save(bool $check, bool $banner): void
    {
        $this->settings->save(self::CHECK, $check);
        $this->settings->save(self::BANNER, $banner);
    }

    /**
     * The install's minute of the day (UTC) for the daily check, chosen at
     * random the first time it is asked for and kept.
     */
    public function minute(): int
    {
        $value = $this->settings->find(self::MINUTE)?->value;
        if (is_int($value) && $value >= 0 && $value < 1440) {
            return $value;
        }
        $minute = random_int(0, 1439);
        $this->settings->save(self::MINUTE, $minute);

        return $minute;
    }

    public function status(): UpdateStatus
    {
        $value = $this->settings->find(self::STATUS)?->value;

        return is_array($value) ? UpdateStatus::fromArray($value) : new UpdateStatus();
    }

    public function saveStatus(UpdateStatus $status): void
    {
        $this->settings->save(self::STATUS, $status->toArray());
    }

    /** The version this admin dismissed the banner for, if any. */
    public function dismissed(User $user): ?SemVer
    {
        $value = $this->settings->find(self::DISMISSED, SettingScope::User, $user->id)?->value;

        return is_string($value) ? SemVer::parse($value) : null;
    }

    /** Hide the banner for this version, for this admin, for good. */
    public function dismiss(User $user, SemVer $version): void
    {
        $this->settings->save(self::DISMISSED, (string) $version, SettingScope::User, $user->id);
    }
}
