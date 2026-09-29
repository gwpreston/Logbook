<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Purchase and sale paperwork (spec.md §6 Attachment, §7.12): attachments
 * with owner type `purchase` or `sale`, owner_id = the vehicle's id.
 *
 * `attachments.owner_type` is a plain string(16) column with no check
 * constraint or native enum on any engine, so nothing needs widening and
 * up() changes nothing. The migration exists to move the schema version:
 * 1.3.x reads owner types through an enum that does not know `purchase` or
 * `sale` and would fail on the print view and on deleting a vehicle, so a
 * 1.4.0 backup must never restore into it (the backup check compares schema
 * versions).
 *
 * Rolling back removes the rows of purchase and sale files, owner types an
 * older version cannot read (the files themselves stay under UPLOAD_PATH),
 * as 20261005100000 did for its owner types.
 */
final class AddPurchaseSalePaperwork extends AbstractMigration
{
    public function up(): void
    {
    }

    public function down(): void
    {
        $this->execute('DELETE FROM attachments WHERE owner_type IN (?, ?)', ['purchase', 'sale']);
    }
}
