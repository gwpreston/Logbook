<?php

declare(strict_types=1);

namespace Logbook\Support\Version;

/**
 * The installed release, from `VERSION` (Kernel::version()): what the
 * update check compares GitHub's latest release with (spec.md §7.31).
 */
final readonly class InstalledVersion
{
    public function __construct(public string $version)
    {
    }

    /** Null for a build without a release number (`dev`). */
    public function semVer(): ?SemVer
    {
        return SemVer::parse($this->version);
    }
}
