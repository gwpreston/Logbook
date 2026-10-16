<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

/**
 * Files that came with an app's export (spec.md §7.13): Fuelio's backup
 * photos, or Tracktor's uploads folder as a ZIP or a folder. Each file is
 * found by its base name and handed over as a private temporary copy
 * under a name Logbook chooses, never at a path taken from the source.
 */
interface AppFiles
{
    public function has(string $name): bool;

    /**
     * A temporary copy of the file, or null when there is none of that name
     * or it can't be read.
     */
    public function extract(string $name): ?string;

    /**
     * Remove every temporary copy.
     */
    public function close(): void;
}
