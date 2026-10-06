<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

use Logbook\Repository\SettingRepository;

/**
 * Reads and writes the demo marker (spec.md §7.36). Only the demo seeding
 * path calls `write()`.
 */
final readonly class DemoMarkers
{
    public function __construct(private SettingRepository $settings)
    {
    }

    public function find(): ?DemoMarker
    {
        return DemoMarker::fromValue($this->settings->find(DemoMarker::SETTING)?->value);
    }

    public function write(DemoMarker $marker): void
    {
        $this->settings->save(DemoMarker::SETTING, $marker->toArray());
    }
}
