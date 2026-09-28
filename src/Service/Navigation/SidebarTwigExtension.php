<?php

declare(strict_types=1);

namespace Logbook\Service\Navigation;

use Logbook\Domain\User\User;
use Logbook\Service\Reminder\DueCounter;
use Logbook\Service\Vehicle\VehicleService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `sidebar(user)` for the layout: the active vehicles and their due counts.
 * The layout calls it once per page, so it costs two small queries.
 */
final class SidebarTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly VehicleService $vehicles,
        private readonly DueCounter $counter,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sidebar', fn (User $user): Sidebar => new Sidebar(
                $this->vehicles->listFleet($user),
                $this->counter->counts($user),
            )),
        ];
    }
}
