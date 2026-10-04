<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

/**
 * One CSV of an app's export, split into its sections (a Fuelio CSV is one
 * vehicle).
 */
final readonly class AppFile
{
    /**
     * @param string $name the file's name, for messages ("vehicle-1-local.csv")
     * @param array<string, AppSection> $sections by name
     */
    public function __construct(
        public string $name,
        public array $sections,
    ) {
    }

    public function section(string $name): ?AppSection
    {
        return $this->sections[$name] ?? null;
    }

    /**
     * Rows across every section.
     */
    public function rowCount(): int
    {
        return array_sum(array_map(static fn (AppSection $s): int => count($s->rows), $this->sections));
    }
}
