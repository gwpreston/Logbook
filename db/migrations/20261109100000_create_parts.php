<?php

declare(strict_types=1);

use Logbook\Support\Database\Migration;

/**
 * Parts and modifications (spec.md §6 Modification, Part and PartFitting;
 * §7.39, Phase 50.1, decided 2026-10-14, #416–#428).
 *
 * - `modifications`: the build work. `done_on` and `reverted_on` are
 *   calendar dates, never converted through a time zone; the cost is a
 *   ledger line in the `build` group, computed on every read (§7.7), so
 *   nothing is copied anywhere.
 * - `parts`: the physical items. `status` is replayed from the fittings
 *   and stored so lists can query it, as a tyre's is.
 * - `part_fittings`: each spell on the vehicle. The record that fitted a
 *   part cascades (the service deletes those rows first anyway); the one
 *   that removed it is set null (the service reopens the fitting first).
 * - Four links on odometer readings: a modification's mileage and its
 *   revert's (sources `modification` and `mod_revert`), and a
 *   fitting's or a removal's when no record made it (`part_fitted` and
 *   `part_removed`, #421), removed with them.
 *
 * Files are attachments with owner types `modification` and `part`.
 * Rolling back turns their readings into manual ones (no mileage is lost),
 * removes those attachment rows (the files stay under UPLOAD_PATH), then
 * drops the columns and the tables; the `build` lines go with them.
 */
final class CreateParts extends Migration
{
    /** expense_entries.note */
    private const int EXPENSE_NOTE_MAX = 500;

    public function up(): void
    {
        $km = ['precision' => 12, 'scale' => 3, 'null' => true];
        $money = ['precision' => 14, 'scale' => 3, 'null' => true];

        $this->table('modifications')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('created_by', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('title', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('category', 'string', ['limit' => 16, 'null' => false, 'default' => 'other'])
            ->addColumn('done_on', 'date', ['null' => false])
            ->addColumn('odometer_km', 'decimal', $km)
            ->addColumn('cost', 'decimal', ['precision' => 14, 'scale' => 3, 'null' => false, 'default' => '0'])
            ->addColumn('fitted_by', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('declared_on', 'date', ['null' => true])
            ->addColumn('reverted_on', 'date', ['null' => true])
            ->addColumn('reverted_odometer_km', 'decimal', $km)
            ->addColumn('reverted_note', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'done_on'], ['name' => 'modifications_vehicle_done_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'modifications_vehicle_fk',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'modifications_created_by_fk',
            ])
            ->create();

        $this->table('parts')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('created_by', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('brand', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('part_number', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('serial_number', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('supplier', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('bought_on', 'date', ['null' => true])
            ->addColumn('price', 'decimal', $money)
            ->addColumn('part_condition', 'string', ['limit' => 16, 'null' => false, 'default' => 'new'])
            ->addColumn('origin', 'string', ['limit' => 8, 'null' => false, 'default' => 'fitted'])
            ->addColumn('warranty_months', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('warranty_km', 'decimal', $km)
            ->addColumn('warranty_from', 'string', ['limit' => 8, 'null' => true])
            ->addColumn('warranty_provider', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('warranty_terms', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 8, 'null' => false, 'default' => 'stored'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'status'], ['name' => 'parts_vehicle_status_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'parts_vehicle_fk',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'parts_created_by_fk',
            ])
            ->create();

        $this->table('part_fittings')
            ->addColumn('part_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('fitted_on', 'date', ['null' => false])
            ->addColumn('fitted_odometer_km', 'decimal', $km)
            ->addColumn('fitted_maintenance_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('fitted_modification_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('fitted_by_revert', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('removed_on', 'date', ['null' => true])
            ->addColumn('removed_odometer_km', 'decimal', $km)
            ->addColumn('removed_maintenance_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('removed_modification_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('removed_by_revert', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('outcome', 'string', ['limit' => 8, 'null' => true])
            ->addColumn('sold_price', 'decimal', $money)
            ->addColumn('replaced_by_part_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['part_id', 'fitted_on'], ['name' => 'part_fittings_part_idx'])
            ->addIndex(['fitted_maintenance_id'], ['name' => 'part_fittings_fitted_maint_idx'])
            ->addIndex(['fitted_modification_id'], ['name' => 'part_fittings_fitted_mod_idx'])
            ->addIndex(['removed_maintenance_id'], ['name' => 'part_fittings_removed_maint_idx'])
            ->addIndex(['removed_modification_id'], ['name' => 'part_fittings_removed_mod_idx'])
            ->addForeignKey('part_id', 'parts', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'part_fittings_part_fk',
            ])
            ->addForeignKey('fitted_maintenance_id', 'maintenance_entries', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'part_fittings_fitted_maint_fk',
            ])
            ->addForeignKey('fitted_modification_id', 'modifications', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'part_fittings_fitted_mod_fk',
            ])
            ->addForeignKey('removed_maintenance_id', 'maintenance_entries', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'part_fittings_removed_maint_fk',
            ])
            ->addForeignKey('removed_modification_id', 'modifications', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'part_fittings_removed_mod_fk',
            ])
            ->addForeignKey('replaced_by_part_id', 'parts', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'part_fittings_replaced_by_fk',
            ])
            ->create();

        $this->table('odometer_readings')
            ->addColumn('modification_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('modification_revert_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('part_fitted_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('part_removed_id', 'integer', ['null' => true, 'signed' => false])
            ->addIndex(['modification_id'], ['unique' => true, 'name' => 'odometer_readings_mod_uniq'])
            ->addIndex(['modification_revert_id'], ['unique' => true, 'name' => 'odometer_readings_mod_revert_uniq'])
            ->addIndex(['part_fitted_id'], ['unique' => true, 'name' => 'odometer_readings_part_fitted_uniq'])
            ->addIndex(['part_removed_id'], ['unique' => true, 'name' => 'odometer_readings_part_removed_uniq'])
            ->addForeignKey('modification_id', 'modifications', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'odometer_readings_mod_fk',
            ])
            ->addForeignKey('modification_revert_id', 'modifications', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'odometer_readings_mod_revert_fk',
            ])
            ->addForeignKey('part_fitted_id', 'part_fittings', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'odometer_readings_part_fitted_fk',
            ])
            ->addForeignKey('part_removed_id', 'part_fittings', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'odometer_readings_part_removed_fk',
            ])
            ->update();
    }

    public function down(): void
    {
        // Keep the mileage: these readings become ordinary manual ones.
        // The links still there: a rollback stopped part-way on MySQL or MariaDB may have
        // dropped some of them already.
        $links = array_values(array_filter(
            ['modification_id', 'modification_revert_id', 'part_fitted_id', 'part_removed_id'],
            fn (string $column): bool => $this->table('odometer_readings')->hasColumn($column),
        ));
        $this->execute(
            'UPDATE odometer_readings SET source = ?'
                . implode('', array_map(static fn (string $column): string => ', ' . $column . ' = NULL', $links))
                . ' WHERE source IN (?, ?, ?, ?)',
            ['manual', 'modification', 'mod_revert', 'part_fitted', 'part_removed'],
        );
        // A modification may have been an expense or a service record before
        // *Make this a modification*: each comes back as an Other expense with
        // its files, so no money spent goes missing. Parts and their fittings
        // have nowhere to go and are dropped.
        //
        // Restartable (MySQL and MariaDB commit at every DDL statement, so a
        // rollback can stop part-way): each modification is deleted as soon as
        // its expense is written, so a retry never copies it twice, and every
        // schema step is skipped once done.
        if ($this->hasTable('modifications')) {
            $modifications = $this->rows(
                'SELECT m.id, m.vehicle_id, v.user_id AS owner_id, m.title, m.notes, m.done_on, m.cost,'
                    . ' m.created_at, m.updated_at FROM modifications m INNER JOIN vehicles v ON v.id = m.vehicle_id'
                    . ' ORDER BY m.id',
                [],
            );
            foreach ($modifications as $modification) {
                $this->restoreAsExpense($modification);
            }
        }
        $this->execute('DELETE FROM attachments WHERE owner_type IN (?, ?)', ['modification', 'part']);

        $readings = $this->table('odometer_readings');
        foreach (['part_removed_id', 'part_fitted_id', 'modification_revert_id', 'modification_id'] as $column) {
            if ($readings->hasForeignKey($column)) {
                $readings->dropForeignKey($column)->update();
            }
        }
        foreach (
            [
                'odometer_readings_part_removed_uniq',
                'odometer_readings_part_fitted_uniq',
                'odometer_readings_mod_revert_uniq',
                'odometer_readings_mod_uniq',
            ] as $index
        ) {
            if ($readings->hasIndexByName($index)) {
                $readings->removeIndexByName($index)->update();
            }
        }
        foreach (['part_removed_id', 'part_fitted_id', 'modification_revert_id', 'modification_id'] as $column) {
            if ($readings->hasColumn($column)) {
                $readings->removeColumn($column)->update();
            }
        }

        foreach (['part_fittings', 'parts', 'modifications'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }

    /**
     * One modification as an Other expense owned by the vehicle's owner: its date, cost and times,
     * its files, and as the note its title, or its whole text when
     * *Make this a modification* kept a longer note in Notes (#431), cut to an
     * expense note's 500 characters. Then the modification goes, so a retried
     * rollback doesn't copy it again.
     *
     * @param array<string, mixed> $modification
     */
    private function restoreAsExpense(array $modification): void
    {
        $id = self::int($modification['id'] ?? null);
        $title = self::text($modification['title'] ?? null);
        $notes = self::text($modification['notes'] ?? null);
        $note = $notes !== '' && str_starts_with($notes, $title) ? $notes : $title;
        // Found again by a marker note, without relying on lastInsertId(). Its random part
        // means no note anyone typed can match it (#436).
        $marker = 'logbook-rollback:' . bin2hex(random_bytes(16));
        $this->table('expense_entries')->insert([
            'vehicle_id' => self::int($modification['vehicle_id'] ?? null),
            // The vehicle's owner, never the modification's author: a member without costs who
            // added it would otherwise see the cost the owner typed as their own entry (#435).
            'created_by' => is_numeric($modification['owner_id'] ?? null) ? (int) $modification['owner_id'] : null,
            'spent_on' => self::text($modification['done_on'] ?? null),
            'category' => 'other',
            'amount' => self::text($modification['cost'] ?? null),
            'note' => $marker,
            'created_at' => self::text($modification['created_at'] ?? null),
            'updated_at' => self::text($modification['updated_at'] ?? null),
        ])->saveData();
        $expense = $this->rows('SELECT id FROM expense_entries WHERE note = ?', [$marker])[0] ?? null;
        if ($expense === null) {
            return;
        }
        $expenseId = self::int($expense['id'] ?? null);
        $this->execute(
            'UPDATE attachments SET owner_type = ?, owner_id = ? WHERE owner_type = ? AND owner_id = ?',
            ['expense', $expenseId, 'modification', $id],
        );
        $this->execute(
            'UPDATE expense_entries SET note = ? WHERE id = ?',
            [mb_substr($note, 0, self::EXPENSE_NOTE_MAX), $expenseId],
        );
        // Its readings were unlinked above, and its fittings go with the tables.
        $this->execute('DELETE FROM modifications WHERE id = ?', [$id]);
    }

    /**
     * @param list<int|string> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->query($sql, $params);
        if (!$statement instanceof PDOStatement) {
            return [];
        }
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = array_combine(array_map(strval(...), array_keys($row)), array_values($row));
            }
        }
        // SQLite keeps the table locked while a cursor is open.
        $statement->closeCursor();

        return $rows;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
