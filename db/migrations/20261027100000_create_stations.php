<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Fuel stations (spec.md §6 Station, StationFavourite, Place; §7.33,
 * Phase 30.1).
 *
 * - `stations`: shared by every user of the install. A position is two
 *   DECIMAL(9,6) columns, both or neither (checked by the form). A merged
 *   station keeps its row and points at the one it became (`merged_into`),
 *   so old links still resolve.
 * - `station_favourites`: per user, unique per user and station.
 * - `places`: per user (Home, Work, ...), private to them.
 * - `fuel_entries.station_id`: the linked station; the `station` text
 *   column is kept as it was.
 *
 * Rolling back drops the link and the three tables. Explicit up/down
 * rather than change(): SQLite has no named foreign keys, so dropping by
 * column is the portable reversal.
 */
final class CreateStations extends AbstractMigration
{
    public function up(): void
    {
        $position = ['precision' => 9, 'scale' => 6];
        $this->table('stations')
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('brand', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('address', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('postcode', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('country', 'string', ['limit' => 2, 'null' => true])
            ->addColumn('latitude', 'decimal', ['null' => true] + $position)
            ->addColumn('longitude', 'decimal', ['null' => true] + $position)
            ->addColumn('grades', 'json', ['null' => true])
            ->addColumn('opening_hours', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('created_by', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('merged_into', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['name'], ['name' => 'stations_name_idx'])
            ->addIndex(['latitude', 'longitude'], ['name' => 'stations_position_idx'])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'stations_created_by_fk',
            ])
            ->addForeignKey('merged_into', 'stations', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'stations_merged_into_fk',
            ])
            ->create();

        $this->table('station_favourites')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('station_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'station_id'], ['unique' => true, 'name' => 'station_favourites_user_station_uq'])
            ->addIndex(['station_id'], ['name' => 'station_favourites_station_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'station_favourites_user_fk',
            ])
            ->addForeignKey('station_id', 'stations', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'station_favourites_station_fk',
            ])
            ->create();

        $this->table('places')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('latitude', 'decimal', ['null' => false] + $position)
            ->addColumn('longitude', 'decimal', ['null' => false] + $position)
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'sort_order'], ['name' => 'places_user_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'places_user_fk',
            ])
            ->create();

        $this->table('fuel_entries')
            ->addColumn('station_id', 'integer', ['null' => true, 'signed' => false, 'after' => 'station'])
            ->addIndex(['station_id'], ['name' => 'fuel_entries_station_idx'])
            ->addForeignKey('station_id', 'stations', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fuel_entries_station_fk',
            ])
            ->update();
    }

    public function down(): void
    {
        $entries = $this->table('fuel_entries');
        $entries->dropForeignKey('station_id')->update();
        $entries->removeIndexByName('fuel_entries_station_idx')->update();
        $entries->removeColumn('station_id')->update();
        $this->table('places')->drop()->save();
        $this->table('station_favourites')->drop()->save();
        $this->table('stations')->drop()->save();
    }
}
