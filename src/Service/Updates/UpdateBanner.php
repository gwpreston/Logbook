<?php

declare(strict_types=1);

namespace Logbook\Service\Updates;

use Logbook\Domain\User\User;
use Logbook\Service\Jobs\AdminNotice;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Version\InstalledVersion;
use Logbook\Support\Version\SemVer;

/**
 * The update banner in the dashboard's admin notice area (spec.md §7.31):
 * while checking and the banner are on, the last check succeeded and its
 * release is newer than this install, unless this admin dismissed that
 * version. The caller has already checked the user is an admin.
 */
final readonly class UpdateBanner
{
    public const string KEY_PREFIX = 'update.';
    public const string DOCKER_COMMAND = 'docker compose pull && docker compose up -d';

    public function __construct(
        private UpdateSettings $settings,
        private InstalledVersion $installed,
        private AppSettings $app,
    ) {
    }

    public function for(User $user): ?AdminNotice
    {
        if (!$this->settings->checking() || !$this->settings->banner()) {
            return null;
        }
        $status = $this->settings->status();
        $latest = $status->latest === null ? null : SemVer::parse($status->latest);
        $installed = $this->installed->semVer();
        if (
            $status->error !== null
            || $latest === null
            || $status->releaseUrl === null
            || $installed === null
            || !$latest->isNewerThan($installed)
        ) {
            return null;
        }
        $dismissed = $this->settings->dismissed($user);
        if ($dismissed !== null && !$latest->isNewerThan($dismissed)) {
            return null;
        }

        $name = $status->releaseName;
        $named = $name !== null && !in_array(
            strtolower($name),
            array_map('strtolower', [(string) $latest, 'v' . $latest, 'Logbook ' . $latest, 'Logbook v' . $latest]),
            true,
        );

        return new AdminNotice(
            key: self::KEY_PREFIX . $latest,
            messageKey: $named ? 'notices.update.message_named' : 'notices.update.message',
            params: ['latest' => (string) $latest, 'installed' => (string) $installed, 'name' => $named ? $name : null],
            linkRoute: null,
            linkData: [],
            linkLabelKey: '',
            level: 'info',
            links: [
                ['url' => $status->releaseUrl, 'labelKey' => 'notices.update.release_notes'],
                ['url' => $this->upgradeGuide($latest), 'labelKey' => 'notices.update.how_to_upgrade'],
            ],
            detailKey: $this->app->docker ? 'notices.update.docker' : 'notices.update.bare',
            detailCode: $this->app->docker ? self::DOCKER_COMMAND : null,
        );
    }

    /** The release's own upgrade guide, at its tag (#116). */
    public function upgradeGuide(SemVer $latest): string
    {
        return sprintf('https://github.com/%s/blob/v%s/docs/deployment.md#upgrading', $this->settings->repository(), $latest);
    }
}
