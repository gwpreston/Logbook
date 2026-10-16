<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

use Logbook\Service\Import\App\AppRowStatus;

/**
 * A whole Tracktor export, mapped and previewed: one TracktorVehiclePreview
 * per vehicle in the file, imported together in one transaction.
 */
final readonly class TracktorPreview
{
    /**
     * @param list<TracktorVehiclePreview> $vehicles in the file's order
     */
    public function __construct(
        public TracktorExport $export,
        public TracktorImportOptions $options,
        public array $vehicles,
        /** Whether the uploads came with it. */
        public bool $filesGiven,
    ) {
    }

    public function total(AppRowStatus $status): int
    {
        return array_sum(array_map(
            static fn (TracktorVehiclePreview $v): int => $v->skipped ? 0 : $v->total($status),
            $this->vehicles,
        ));
    }

    /**
     * Files the rows point at that stay behind because the uploads weren't
     * given.
     */
    public function filesLeftBehind(): int
    {
        if ($this->filesGiven) {
            return 0;
        }

        return array_sum(array_map(
            static fn (TracktorVehiclePreview $v): int => $v->skipped ? 0 : count($v->rows(TracktorVehiclePreview::FILES)),
            $this->vehicles,
        ));
    }

    /**
     * @param list<TracktorVehiclePreview> $vehicles
     */
    public function withVehicles(array $vehicles): self
    {
        return new self($this->export, $this->options, $vehicles, $this->filesGiven);
    }
}
