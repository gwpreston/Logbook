<?php

declare(strict_types=1);

namespace Logbook\Service\Backup;

/**
 * Picks one user's part out of a full dump (spec.md §7.13
 * `bin/export-user.php`), for moving someone to an install of their own:
 * their account (an admin there, since it will be the only one), keys and
 * own settings, the vehicles they own with every entry, schedule, reminder,
 * delivery and file. Shares, other users and install-wide settings (AI
 * connections among them) are left out, and every entry names them as its
 * author: in the new install they added everything.
 */
final class UserExport
{
    /** Tables whose rows belong to a vehicle by `vehicle_id`. */
    private const array BY_VEHICLE = [
        'fuel_entries',
        'maintenance_schedules',
        'maintenance_entries',
        'compliance_documents',
        'tyre_sets',
        'tyres',
        'tyre_changes',
        'odometer_readings',
        'attachments',
        'reminders',
        'expense_entries',
        'vehicle_valuations',
        'trips',
        'incidents',
        'finance_agreements',
        // Phase 31: where the vehicles' imported rows came from.
        'import_sources',
    ];

    /** Author columns, set to the exported user. */
    private const array AUTHORS = ['created_by', 'uploaded_by', 'imported_by'];

    /**
     * @param array<string, list<array<string, string|null>>> $tables every backed-up table => its rows
     * @return array{tables: array<string, list<array<string, string|null>>>, files: list<string>}
     */
    public static function of(array $tables, int $userId): array
    {
        $user = (string) $userId;
        $keep = static fn (string $table, callable $test): array => array_values(array_filter($tables[$table] ?? [], $test));

        $out = [];
        $out['users'] = array_map(
            static fn (array $row): array => ['is_admin' => '1', 'disabled_at' => null] + $row,
            $keep('users', static fn (array $row): bool => $row['id'] === $user),
        );
        $out['api_keys'] = $keep('api_keys', static fn (array $row): bool => $row['user_id'] === $user);
        // Phase 23.1: their linked single sign-on accounts, for the same provider there.
        $out['user_identities'] = $keep('user_identities', static fn (array $row): bool => $row['user_id'] === $user);
        $out['settings'] = $keep(
            'settings',
            static fn (array $row): bool => $row['scope'] === 'user' && $row['owner_id'] === $user,
        );
        $out['vehicles'] = $keep('vehicles', static fn (array $row): bool => $row['user_id'] === $user);
        $vehicles = array_column($out['vehicles'], 'id');

        foreach (self::BY_VEHICLE as $table) {
            $out[$table] = array_map(
                static function (array $row) use ($user): array {
                    foreach (self::AUTHORS as $column) {
                        if (array_key_exists($column, $row)) {
                            // A derived reading has no author of its own.
                            $row[$column] = $column === 'created_by' && ($row['source'] ?? 'manual') !== 'manual' ? null : $user;
                        }
                    }

                    return $row;
                },
                $keep($table, static fn (array $row): bool => in_array($row['vehicle_id'], $vehicles, true)),
            );
        }
        // Phase 27.1: a driver who is another user here is kept by name.
        $names = array_column($tables['users'] ?? [], 'display_name', 'id');
        $out['incidents'] = array_map(
            static function (array $row) use ($user, $names): array {
                $driver = $row['driver_user_id'] ?? null;
                if ($driver !== null && $driver !== $user) {
                    $row['driver_name'] ??= $names[$driver] ?? null;
                    $row['driver_user_id'] = null;
                }

                return $row;
            },
            $out['incidents'],
        );
        $changes = array_column($out['tyre_changes'], 'id');
        $out['tyre_change_lines'] = $keep(
            'tyre_change_lines',
            static fn (array $row): bool => in_array($row['change_id'], $changes, true),
        );
        // Phase 29.1: the agreements' payment events and settlement quotes.
        $agreements = array_column($out['finance_agreements'], 'id');
        foreach (['finance_payment_events', 'settlement_quotes'] as $table) {
            $out[$table] = $keep(
                $table,
                static fn (array $row): bool => in_array($row['agreement_id'], $agreements, true),
            );
        }
        $reminders = array_column($out['reminders'], 'id');
        $out['reminder_deliveries'] = $keep(
            'reminder_deliveries',
            static fn (array $row): bool => $row['user_id'] === $user && in_array($row['reminder_id'], $reminders, true),
        );
        $out['vehicle_shares'] = [];
        // Phase 22: the user's own saved journeys and mileage rates.
        $out['saved_journeys'] = $keep('saved_journeys', static fn (array $row): bool => $row['user_id'] === $user);
        $out['mileage_rate_sets'] = $keep('mileage_rate_sets', static fn (array $row): bool => $row['user_id'] === $user);
        // Phase 24: the checks they hid on their own vehicles.
        $out['attention_hidden'] = $keep(
            'attention_hidden',
            static fn (array $row): bool => $row['user_id'] === $user && in_array($row['vehicle_id'], $vehicles, true),
        );

        // Phase 30.1: their places and favourites, and the stations their
        // fill-ups and favourites use, with the ones those were merged into.
        $out['places'] = $keep('places', static fn (array $row): bool => $row['user_id'] === $user);
        $out['station_favourites'] = $keep('station_favourites', static fn (array $row): bool => $row['user_id'] === $user);
        $stationRows = array_column($tables['stations'] ?? [], null, 'id');
        $wanted = array_filter([
            ...array_column($out['fuel_entries'], 'station_id'),
            ...array_column($out['station_favourites'], 'station_id'),
        ], is_string(...));
        $stations = [];
        while ($wanted !== []) {
            $id = array_pop($wanted);
            if (isset($stations[$id]) || !isset($stationRows[$id])) {
                continue;
            }
            $stations[$id] = $stationRows[$id];
            if (is_string($stationRows[$id]['merged_into'] ?? null)) {
                $wanted[] = $stationRows[$id]['merged_into'];
            }
        }
        ksort($stations);
        $out['stations'] = array_values(array_map(
            // Another user's station is the exported user's in the new install.
            static fn (array $row): array => ['created_by' => $user] + $row,
            $stations,
        ));

        // Phase 30.2: their price alerts (on their favourites, carried above),
        // and the listed price changes of the exported stations' links.
        $out['price_alerts'] = $keep('price_alerts', static fn (array $row): bool => $row['user_id'] === $user);
        $links = [];
        foreach ($out['stations'] as $row) {
            if (is_string($row['provider'] ?? null) && is_string($row['provider_ref'] ?? null)) {
                $links[$row['provider'] . "\n" . $row['provider_ref']] = true;
            }
        }
        $out['listed_price_changes'] = $keep(
            'listed_price_changes',
            static function (array $row) use ($links): bool {
                $provider = $row['provider'] ?? null;
                $ref = $row['provider_ref'] ?? null;

                return is_string($provider) && is_string($ref) && isset($links[$provider . "\n" . $ref]);
            },
        );

        // Phase 26.1: AI connections are the install's, not the user's.
        $out['ai_connections'] = [];
        $out['ai_models'] = [];
        $out['ai_tasks'] = [];

        $files = array_values(array_filter([
            ...array_column($out['vehicles'], 'photo_path'),
            ...array_column($out['attachments'], 'stored_path'),
            // Phase 33.1: their avatar.
            ...array_column($out['users'], 'avatar_path'),
        ], is_string(...)));

        return ['tables' => $out, 'files' => $files];
    }
}
