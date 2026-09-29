<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

/**
 * The paperwork the seller has chosen (spec.md §7.19): the kinds offered,
 * the kinds ticked, and every file of those kinds, each marked included or
 * left out. The summary's count, the *Choose files* list and the ZIP all
 * read this one result.
 */
final readonly class PaperworkSelection
{
    /**
     * @param list<PaperworkKind> $offered
     * @param list<PaperworkKind> $chosen
     * @param list<PaperworkFile> $files every file of the chosen kinds, oldest first
     */
    public function __construct(
        public array $offered,
        public array $chosen,
        public array $files,
    ) {
    }

    /**
     * @return list<PaperworkFile> the files that go in the ZIP
     */
    public function included(): array
    {
        return array_values(array_filter($this->files, static fn (PaperworkFile $file): bool => $file->included));
    }

    public function count(): int
    {
        return count($this->included());
    }

    public function isChosen(PaperworkKind $kind): bool
    {
        return in_array($kind, $this->chosen, true);
    }

    /**
     * Ids of the files the seller unticked, for the ZIP link.
     *
     * @return list<int>
     */
    public function excludedIds(): array
    {
        return array_values(array_map(
            static fn (PaperworkFile $file): int => $file->id(),
            array_filter($this->files, static fn (PaperworkFile $file): bool => !$file->included),
        ));
    }
}
