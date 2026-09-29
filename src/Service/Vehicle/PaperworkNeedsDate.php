<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Vehicle\VehicleData;
use RuntimeException;

/**
 * A vehicle save refused because purchase or sale paperwork would be left
 * without its date (spec.md §7.1, §7.12). The files show only on the
 * *Bought* and *Sold* milestones, and a milestone exists only while its
 * date is set, so no file may ever be left with nowhere to show: files
 * without the date are refused ("Add the sale date to attach the sale
 * paperwork"), and so is clearing the date while files are attached
 * ("Remove the sale paperwork first, or keep the sale date"). The same
 * shape as TyresBlockTypeChange.
 */
final class PaperworkNeedsDate extends RuntimeException
{
    public function __construct(
        /** AttachmentOwner::Purchase or AttachmentOwner::Sale. */
        public readonly AttachmentOwner $side,
        /** True when the date is being cleared with files attached; false for new files without it. */
        public readonly bool $clearing,
    ) {
        parent::__construct(sprintf('The %s paperwork needs the %s date.', $side->value, $side->value));
    }

    /**
     * The refusal for saving $data with these files, or null when every file
     * has its date. $kept are the files already attached, $added the new
     * ones; clearing a date with files kept is reported before new files.
     */
    public static function check(VehicleData $data, int $keptPurchase, int $addedPurchase, int $keptSale, int $addedSale): ?self
    {
        foreach (
            [
            [AttachmentOwner::Purchase, $data->purchaseDate === null, $keptPurchase, $addedPurchase],
            [AttachmentOwner::Sale, $data->saleDate === null, $keptSale, $addedSale],
            ] as [$side, $undated, $kept, $added]
        ) {
            if ($undated && $kept > 0) {
                return new self($side, true);
            }
            if ($undated && $added > 0) {
                return new self($side, false);
            }
        }

        return null;
    }

    /**
     * The form field the error belongs to: the date being cleared, or the
     * input of the new files.
     */
    public function field(): string
    {
        return $this->clearing ? $this->side->value . '_date' : $this->side->value . '_attachments';
    }

    public function messageKey(): string
    {
        return 'vehicle.error.' . $this->side->value . ($this->clearing ? '_date_has_files' : '_files_need_date');
    }
}
