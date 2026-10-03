<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Live fuel prices (spec.md §6 ProviderStation, ProviderPrice,
 * ListedPriceChange, PriceAlert, FuelPriceSecret, Station; §7.34,
 * Phase 30.2).
 *
 * - `provider_stations` and `provider_prices`: the feed's copy, re-synced
 *   and never backed up. Unique per provider and the feed's own id.
 * - `listed_price_changes`: each listed price change of a tracked station,
 *   keyed by provider and the feed's id (not a row id), so it survives a
 *   re-sync and a restore (decided 2026-10-03, #143, #144).
 * - `price_alerts`: per user, station and grade (#138).
 * - `fuel_price_secrets`: the provider's credentials, sealed or `env:NAME`,
 *   never backed up.
 * - `stations.provider`, `stations.provider_ref`: the link, with no foreign
 *   key, unique together; `stations.keep_my_details`.
 *
 * Explicit up/down rather than change(): SQLite has no named foreign keys,
 * so dropping by column is the portable reversal.
 */
final class CreateFuelPrices extends AbstractMigration
{
    public function up(): void
    {
        $position = ['precision' => 9, 'scale' => 6];
        $price = ['precision' => 8, 'scale' => 3];

        $this->table('provider_stations')
            ->addColumn('provider', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('provider_ref', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('brand', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('address', 'string', ['limit' => 300, 'null' => true])
            ->addColumn('postcode', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('latitude', 'decimal', ['null' => true] + $position)
            ->addColumn('longitude', 'decimal', ['null' => true] + $position)
            ->addColumn('opening_hours', 'json', ['null' => true])
            ->addColumn('amenities', 'json', ['null' => true])
            ->addColumn('grades', 'json', ['null' => true])
            ->addColumn('temporarily_closed', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addColumn('removed_at', 'datetime', ['null' => true])
            ->addIndex(['provider', 'provider_ref'], ['unique' => true, 'name' => 'provider_stations_ref_uq'])
            ->addIndex(['latitude', 'longitude'], ['name' => 'provider_stations_position_idx'])
            ->addIndex(['postcode'], ['name' => 'provider_stations_postcode_idx'])
            ->create();

        $this->table('provider_prices')
            ->addColumn('provider_station_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('grade', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('price', 'decimal', ['null' => false] + $price)
            ->addColumn('reported_at', 'datetime', ['null' => false])
            ->addColumn('synced_at', 'datetime', ['null' => false])
            ->addIndex(['provider_station_id', 'grade'], ['unique' => true, 'name' => 'provider_prices_station_grade_uq'])
            ->addIndex(['grade', 'reported_at'], ['name' => 'provider_prices_grade_idx'])
            ->addForeignKey('provider_station_id', 'provider_stations', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'provider_prices_station_fk',
            ])
            ->create();

        $this->table('listed_price_changes')
            ->addColumn('provider', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('provider_ref', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('grade', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('price', 'decimal', ['null' => false] + $price)
            ->addColumn('reported_at', 'datetime', ['null' => false])
            ->addIndex(
                ['provider', 'provider_ref', 'grade', 'reported_at'],
                ['unique' => true, 'name' => 'listed_price_changes_uq'],
            )
            ->addIndex(['reported_at'], ['name' => 'listed_price_changes_reported_idx'])
            ->create();

        $this->table('price_alerts')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('station_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('grade', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('below', 'decimal', ['null' => false] + $price)
            ->addColumn('triggered_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'station_id', 'grade'], ['unique' => true, 'name' => 'price_alerts_user_station_grade_uq'])
            ->addIndex(['station_id'], ['name' => 'price_alerts_station_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'price_alerts_user_fk',
            ])
            ->addForeignKey('station_id', 'stations', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'price_alerts_station_fk',
            ])
            ->create();

        $this->table('fuel_price_secrets')
            ->addColumn('provider', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('slot', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('value', 'text', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['provider', 'slot'], ['unique' => true, 'name' => 'fuel_price_secrets_uq'])
            ->create();

        $this->table('stations')
            ->addColumn('provider', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('provider_ref', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('keep_my_details', 'boolean', ['null' => false, 'default' => false])
            ->addIndex(['provider', 'provider_ref'], ['unique' => true, 'name' => 'stations_provider_ref_uq'])
            ->update();
    }

    public function down(): void
    {
        $stations = $this->table('stations');
        $stations->removeIndexByName('stations_provider_ref_uq')->update();
        $stations->removeColumn('keep_my_details')
            ->removeColumn('provider_ref')
            ->removeColumn('provider')
            ->update();
        $this->table('fuel_price_secrets')->drop()->save();
        $this->table('price_alerts')->drop()->save();
        $this->table('listed_price_changes')->drop()->save();
        $this->table('provider_prices')->drop()->save();
        $this->table('provider_stations')->drop()->save();
    }
}
