<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Logbook\Service\Access\AccessContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `admin_notices()` for the dashboard's notice area, and
 * `scheduler_beacon()` for the layout: whether this signed-in page should
 * send the *On page visits* beacon (spec.md §7.30).
 */
final class JobsTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly AccessContext $context,
        private readonly AdminNotices $notices,
        private readonly JobSettings $settings,
        private readonly SchedulerHealth $health,
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
        ];
    }
}
