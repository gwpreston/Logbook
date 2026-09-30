<?php

declare(strict_types=1);

namespace Logbook\Service\Backup;

/**
 * Picks one user's part out of a full dump (spec.md §7.13
 * `bin/export-user.php`), for moving someone to an install of their own:
 * their account (an admin there, since it will be the only one), keys and
 * own settings, the vehicles they own with every entry, schedule, reminder,
 * delivery and file. Shares, other users and install-wide settings are left
 * out, and every entry names them as its author: in the new install they
 * added everything.
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
    ];

    /** Author columns, set to the exported user. */
    private const array AUTHORS = ['created_by', 'uploaded_by'];

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
        $changes = array_column($out['tyre_changes'], 'id');
        $out['tyre_change_lines'] = $keep(
            'tyre_change_lines',
            static fn (array $row): bool => in_array($row['change_id'], $changes, true),
        );
        $reminders = array_column($out['reminders'], 'id');
        $out['reminder_deliveries'] = $keep(
            'reminder_deliveries',
            static fn (array $row): bool => $row['user_id'] === $user && in_array($row['reminder_id'], $reminders, true),
        );
        $out['vehicle_shares'] = [];

        $files = array_values(array_filter([
            ...array_column($out['vehicles'], 'photo_path'),
            ...array_column($out['attachments'], 'stored_path'),
        ], is_string(...)));

        return ['tables' => $out, 'files' => $files];
    }
}
