<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\User\UserDirectory;
use Logbook\Support\PlateStyle;

/**
 * The plate a vehicle's registration is drawn on (spec.md §8 *Registration
 * plate*): its **owner's** locale region decides, as for the first-MOT
 * suggestion (§7.1), so a shared car looks the same to everyone. Each
 * owner is resolved once per request, however many of their vehicles a
 * page lists.
 */
final class PlateStyleResolver
{
    /** @var array<int, PlateStyle> owner id → style */
    private array $styles = [];

    public function __construct(
        private readonly AccessContext $context,
        private readonly UserDirectory $directory,
    ) {
    }

    public function forVehicle(Vehicle $vehicle): PlateStyle
    {
        return $this->styles[$vehicle->userId] ??= $this->resolve($vehicle->userId);
    }

    public function forget(): void
    {
        $this->styles = [];
    }

    private function resolve(int $ownerId): PlateStyle
    {
        $viewer = $this->context->user();
        $owner = $viewer !== null && $viewer->id === $ownerId ? $viewer : $this->directory->find($ownerId);

        return $owner === null ? PlateStyle::Neutral : PlateStyle::forLocale($owner->preferences->locale);
    }
}
