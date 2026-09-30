<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Trips and mileage claims (spec.md §6 Trip, SavedJourney, MileageRateSet;
 * §7.22, §7.23; Phase 22).
 *
 * - `trips`: the business (and any private) journeys a driver logs. The
 *   date is a calendar date, never converted through a time zone; the
 *   distance is the whole trip in kilometres. A trip writes no odometer
 *   reading: its start and end odometer are evidence on the trip only.
 *   `created_by` is the driver and claimant; deleting the user keeps the
 *   trip (SET NULL), as for every other entry (20261012100000).
 * - `saved_journeys`: a user's repeat journeys (one way, in kilometres).
 * - `mileage_rate_sets`: a user's dated rates. Rates are per unit of
 *   `distance_unit`, the threshold in that unit per tax year.
 *
 * Trip files are attachments with owner type `trip`. Rolling back removes
 * those rows (the files stay under UPLOAD_PATH, as 20261010100000 did) and
 * the `trips` user settings, then drops the tables.
 */
final class CreateTripTables extends AbstractMigration
{
    private const string SETTING = 'trips';

    public function up(): void
    {
        $this->table('trips')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('created_by', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('travelled_on', 'date', ['null' => false])
            ->addColumn('from_place', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('to_place', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('is_return', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('distance_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => false])
            ->addColumn('odometer_start_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true])
            ->addColumn('odometer_end_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true])
            ->addColumn('is_business', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('purpose', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('passengers', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('notes', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'travelled_on'], ['name' => 'trips_vehicle_travelled_idx'])
            ->addIndex(['created_by', 'travelled_on'], ['name' => 'trips_created_by_travelled_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'trips_vehicle_fk',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'trips_created_by_fk',
            ])
            ->create();

        $this->table('saved_journeys')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('from_place', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('to_place', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('distance_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => false])
            ->addColumn('is_return_default', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('purpose_default', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('is_business_default', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'sort_order'], ['name' => 'saved_journeys_user_sort_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'saved_journeys_user_fk',
            ])
            ->create();

        $rate = ['precision' => 10, 'scale' => 4];
        $this->table('mileage_rate_sets')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('effective_from', 'date', ['null' => false])
            ->addColumn('distance_unit', 'string', ['limit' => 2, 'null' => false])
            ->addColumn('currency', 'string', ['limit' => 3, 'null' => false])
            ->addColumn('car_rate', 'decimal', $rate + ['null' => false])
            ->addColumn('car_threshold', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true])
            ->addColumn('car_rate_after', 'decimal', $rate + ['null' => true])
            ->addColumn('bike_rate', 'decimal', $rate + ['null' => true])
            ->addColumn('passenger_rate', 'decimal', $rate + ['null' => true])
            ->addColumn('employer_car_rate', 'decimal', $rate + ['null' => true])
            ->addColumn('employer_bike_rate', 'decimal', $rate + ['null' => true])
            ->addColumn('source', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'effective_from'], ['unique' => true, 'name' => 'mileage_rate_sets_user_from_uq'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'mileage_rate_sets_user_fk',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->execute('DELETE FROM attachments WHERE owner_type = ?', ['trip']);
        $this->execute('DELETE FROM settings WHERE name = ?', [self::SETTING]);

        $this->table('mileage_rate_sets')->drop()->save();
        $this->table('saved_journeys')->drop()->save();
        $this->table('trips')->drop()->save();
    }
}
