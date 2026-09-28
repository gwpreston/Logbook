<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Plug-in hybrids (spec.md §6 Vehicle): the one `hybrid` fuel type is split
 * into `hybrid` (self-charging or mild) and `phev`. A hybrid that has ever
 * been charged in Logbook, archived or not, becomes a plug-in hybrid; every
 * other hybrid stays one. No schema change: `fuel_type` is a plain string of
 * up to 16 characters. `updated_at` is left alone, since the owner changed
 * nothing.
 *
 * The ids are selected first and updated one by one, so no engine has to
 * update a table its own subquery reads.
 */
final class SplitPlugInHybrids extends AbstractMigration
{
    public function up(): void
    {
        $rows = $this->query(
            'SELECT DISTINCT v.id FROM vehicles v INNER JOIN fuel_entries f ON f.vehicle_id = v.id'
            . ' WHERE v.fuel_type = ? AND f.fuel = ?',
            ['hybrid', 'ev'],
        );

        foreach ($rows instanceof PDOStatement ? $rows->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            if (is_int($id) || is_string($id)) {
                $this->execute('UPDATE vehicles SET fuel_type = ? WHERE id = ?', ['phev', (int) $id]);
            }
        }
    }

    /**
     * Lossless: before this migration `hybrid` meant either kind.
     */
    public function down(): void
    {
        $this->execute('UPDATE vehicles SET fuel_type = ? WHERE fuel_type = ?', ['hybrid', 'phev']);
    }
}
