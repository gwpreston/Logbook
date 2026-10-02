<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Logbook\Service\Access\AccessContext;
use Logbook\Service\Updates\UpdateSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `admin_notices()` for the dashboard's notice area, and
 * `scheduler_beacon()` for the layout: whether this signed-in page should
 * send the *On page visits* beacon (spec.md §7.30); `updates_allowed()`
 * for Settings and first-run setup (§7.31).
 */
final class JobsTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly AccessContext $context,
        private readonly AdminNotices $notices,
        private readonly JobSettings $settings,
        private readonly SchedulerHealth $health,
        private readonly UpdateSettings $updates,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_notices', function (): array {
                $user = $this->context->user();

                return $user === null ? [] : $this->notices->for($user);
            }),
            new TwigFunction('scheduler_beacon', fn (): bool => $this->context->user() !== null
                && $this->settings->pageVisits()
                && $this->health->isPassDue()),
            // Settings → Updates exists only while UPDATE_CHECK_ALLOWED is on (spec.md §7.31).
            new TwigFunction('updates_allowed', fn (): bool => $this->updates->allowed()),
        ];
    }
}
