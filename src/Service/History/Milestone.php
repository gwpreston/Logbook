<?php

declare(strict_types=1);

namespace Logbook\Service\History;

use Logbook\Domain\Attachment\AttachmentOwner;

/**
 * A vehicle's own milestones (spec.md §7.16), derived from its row on every
 * read and never stored, like its age. On their day, *First registered* and
 * *Bought* sort below everything else (they happened first) and *Sold*
 * above everything. *Bought* and *Sold* carry the purchase and sale
 * paperwork.
 */
enum Milestone: string
{
    case FirstRegistered = 'first_registered';
    case Bought = 'bought';
    case Sold = 'sold';
    /** *Sold*, for a vehicle written off (spec.md §7.29 *Total loss*): the settlement is the sale. */
    case WrittenOff = 'written_off';
    /** *Sold*, for a PCP handed back (spec.md §7.32 *Ending*): the final payment is the sale. */
    case ReturnedLender = 'returned_lender';
    /** *Sold*, for a lease ended: no sale price. */
    case ReturnedLessor = 'returned_lessor';

    /**
     * Order among the day's lines, newest first: higher comes first; the
     * day's entries are 0.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Sold, self::WrittenOff, self::ReturnedLender, self::ReturnedLessor => 1,
            self::Bought => -1,
            self::FirstRegistered => -2,
        };
    }

    /**
     * Whose files the milestone shows: the purchase's on *Bought*, the
     * sale's on *Sold* (owner id = the vehicle's); none on *First
     * registered*.
     */
    public function filesOwner(): ?AttachmentOwner
    {
        return match ($this) {
            self::Bought => AttachmentOwner::Purchase,
            self::Sold, self::WrittenOff, self::ReturnedLender, self::ReturnedLessor => AttachmentOwner::Sale,
            self::FirstRegistered => null,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::FirstRegistered => 'badge',
            self::Bought => 'key',
            self::Sold => 'sell',
            self::WrittenOff => 'car_crash',
            self::ReturnedLender, self::ReturnedLessor => 'key_off',
        };
    }
}
