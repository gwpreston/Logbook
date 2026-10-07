<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Personal notification channels (spec.md §6 NotificationChannel, §7.11
 * *Personal channels*; Phase 36.2, decided 2026-10-07, #227, #230, #247).
 *
 * - `notification_channels`: one row per user and kind; `settings` holds
 *   the kind's visible fields, `_secrets` the names of its secret fields
 *   that should be saved (a missing one is *Needs setup*) and `_imported`
 *   on channels made from the server's variables.
 * - Each user's personal ntfy topic (`ntfy_url`) and Gotify token
 *   (`gotify_token`) leave their `notifications` setting for rows. Gotify
 *   takes `GOTIFY_URL` and `GOTIFY_PRIORITY`, the server its token was for.
 * - Imported once, then never read again (#247): every admin without their
 *   own ntfy topic gets `NTFY_URL` (and `NTFY_TOKEN`); every admin without
 *   their own Gotify token gets `GOTIFY_URL`, `GOTIFY_TOKEN` and
 *   `GOTIFY_PRIORITY`; a personal topic on `NTFY_URL`'s server gets
 *   `NTFY_TOKEN`, as it was sent with it.
 * - Tokens are sealed exactly as Logbook\Service\Ai\SecretBox seals with
 *   the info `logbook-notify` (repeated here so the migration never depends
 *   on application code; a test pins the two together). A token is never
 *   copied in the clear: without a `SESSION_SECRET` the channel is made
 *   *Needs setup* and a personal token is left where it was (#230).
 * - The `channels` list keeps `email` and `webhook` (the server's webhook).
 *
 * The variables come from `phinx.php` (the option `logbook_env`), which
 * reads the same environment as the app. Only counts are printed.
 *
 * Rolling back puts topics and tokens back in the setting (opening sealed
 * tokens where it can), drops channels made from the variables and every
 * user's notification secret, and drops the table.
 */
final class CreateNotificationChannels extends AbstractMigration
{
    private const string NOTIFICATIONS = 'notifications';
    private const string INFO = 'logbook-notify';
    private const string PREFIX = 'v1:';
    private const int DEFAULT_PRIORITY = 5;

    public function up(): void
    {
        $this->table('notification_channels')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('kind', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('enabled', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('settings', 'json', ['null' => false])
            ->addColumn('last_status', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('last_attempt_at', 'datetime', ['null' => true])
            ->addColumn('last_error', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('failures', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('switched_off_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'kind'], ['unique' => true, 'name' => 'notification_channels_user_kind_uq'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'notification_channels_user_fk',
            ])
            ->create();

        $env = $this->environment();
        $key = $this->key($env);
        [$ntfyServer, $ntfyTopic] = self::splitTopic($env['NTFY_URL'] ?? '');
        $ntfyToken = self::nonEmpty($env['NTFY_TOKEN'] ?? '');
        $gotifyUrl = self::httpUrl($env['GOTIFY_URL'] ?? '');
        $gotifyToken = self::nonEmpty($env['GOTIFY_TOKEN'] ?? '');
        $priority = self::priority($env['GOTIFY_PRIORITY'] ?? '');

        $preferences = [];
        foreach ($this->notificationRows() as [$id, $userId, $value]) {
            $preferences[$userId] = [$id, $value];
        }

        $counts = ['moved' => 0, 'imported' => 0, 'pending' => 0];
        foreach ($this->users() as [$userId, $isAdmin]) {
            [$settingId, $value] = $preferences[$userId] ?? [null, []];
            $channels = is_array($value['channels'] ?? null) ? array_values(array_filter($value['channels'], 'is_string')) : null;
            $enabled = static fn (string $kind): bool => $channels === null || in_array($kind, $channels, true);

            // ntfy: their own topic, else the server's for an admin.
            [$server, $topic] = self::splitTopic(is_string($value['ntfy_url'] ?? null) ? $value['ntfy_url'] : '');
            $imported = false;
            if (($server === null || $topic === null) && $isAdmin && $ntfyServer !== null && $ntfyTopic !== null) {
                [$server, $topic, $imported] = [$ntfyServer, $ntfyTopic, true];
            }
            if ($server !== null && $topic !== null) {
                // The server's token went with any topic on the server's own server.
                $token = $server === $ntfyServer ? $ntfyToken : null;
                $settings = ['url' => $server . '/' . $topic];
                $pending = $this->insertChannel($userId, 'ntfy', $enabled('ntfy'), $settings, $token, $imported, $key);
                $counts[$imported ? 'imported' : 'moved']++;
                $counts['pending'] += $pending ? 1 : 0;
            }
            unset($value['ntfy_url']);

            // Gotify: their own token (on the server's GOTIFY_URL), else the server's for an admin.
            $own = self::nonEmpty(is_string($value['gotify_token'] ?? null) ? $value['gotify_token'] : '');
            $token = $own ?? ($isAdmin && $gotifyUrl !== null ? $gotifyToken : null);
            if ($token !== null) {
                $settings = ['priority' => $priority] + ($gotifyUrl === null ? [] : ['url' => $gotifyUrl]);
                $imported = $own === null;
                $pending = $this->insertChannel($userId, 'gotify', $enabled('gotify'), $settings, $token, $imported, $key);
                $counts[$imported ? 'imported' : 'moved']++;
                $counts['pending'] += $pending ? 1 : 0;
                if ($own !== null && !$pending) {
                    unset($value['gotify_token']);
                }
            }

            if ($channels !== null) {
                $value['channels'] = array_values(array_intersect($channels, ['email', 'webhook']));
            }
            if ($settingId !== null) {
                $this->saveValue($settingId, $value);
            }
        }

        $this->getOutput()?->writeln(sprintf(
            '<info>Notification channels: %d moved, %d imported from the environment, %d need their token entered again.</info>',
            $counts['moved'],
            $counts['imported'],
            $counts['pending'],
        ));
    }

    public function down(): void
    {
        $key = $this->key($this->environment());
        $preferences = [];
        foreach ($this->notificationRows() as [$id, $userId, $value]) {
            $preferences[$userId] = [$id, $value];
        }

        $rows = $this->fetchAll('SELECT user_id, kind, enabled, settings FROM notification_channels ORDER BY id');
        $disabled = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $userId = self::int($row['user_id'] ?? null);
            $kind = self::text($row['kind'] ?? null);
            $settings = is_string($row['settings'] ?? null) ? json_decode($row['settings'], true) : null;
            $settings = is_array($settings) ? $settings : [];
            if (!in_array($kind, ['ntfy', 'gotify'], true) || ($settings['_imported'] ?? false) === true) {
                continue;
            }
            [$settingId, $value] = $preferences[$userId] ?? [null, []];
            if ($kind === 'ntfy' && is_string($settings['url'] ?? null)) {
                $value['ntfy_url'] = $settings['url'];
            }
            if ($kind === 'gotify') {
                $token = $this->openSecret($userId, 'gotify.token', $key);
                if ($token !== null) {
                    $value['gotify_token'] = $token;
                }
            }
            if (!self::truthy($row['enabled'] ?? null)) {
                $disabled[$userId][] = $kind;
            } elseif (is_array($value['channels'] ?? null) && !in_array($kind, $value['channels'], true)) {
                $value['channels'][] = $kind;
            }
            $preferences[$userId] = [$settingId, $value];
        }

        $now = gmdate('Y-m-d H:i:s');
        foreach ($preferences as $userId => [$settingId, $value]) {
            if (isset($disabled[$userId]) && !is_array($value['channels'] ?? null)) {
                // "Every configured channel" but the ones they had switched off.
                $value['channels'] = array_values(array_diff(['email', 'ntfy', 'gotify', 'webhook'], $disabled[$userId]));
            }
            if ($settingId !== null) {
                $this->saveValue($settingId, $value);
            } elseif ($value !== []) {
                $this->table('settings')->insert([
                    'scope' => 'user',
                    'owner_id' => $userId,
                    'name' => self::NOTIFICATIONS,
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->saveData();
            }
        }

        $this->execute('DELETE FROM notification_secrets WHERE owner_user_id IS NOT NULL');
        $this->table('notification_channels')->drop()->save();
    }

    /**
     * Insert one channel; its token (field `token`) is sealed when there is a key.
     *
     * @param array<string, mixed> $settings
     * @return bool whether the token could not be stored (*Needs setup*)
     */
    private function insertChannel(
        int $userId,
        string $kind,
        bool $enabled,
        array $settings,
        ?string $token,
        bool $imported,
        ?string $key,
    ): bool {
        $now = gmdate('Y-m-d H:i:s');
        $secrets = $token === null ? [] : ['token' => $token];
        $pending = false;
        if ($secrets !== []) {
            $settings['_secrets'] = array_keys($secrets);
        }
        if ($imported) {
            $settings['_imported'] = true;
        }
        $this->table('notification_channels')->insert([
            'user_id' => $userId,
            'kind' => $kind,
            'enabled' => $enabled,
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
            'failures' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->saveData();

        foreach ($secrets as $field => $value) {
            if ($key === null) {
                $pending = true;
                continue;
            }
            $this->table('notification_secrets')->insert([
                'owner_user_id' => $userId,
                'name' => $kind . '.' . $field,
                'value' => self::seal($value, $key),
                'created_at' => $now,
                'updated_at' => $now,
            ])->saveData();
        }

        return $pending;
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        $env = $this->getAdapter()->getOption('logbook_env');

        $out = [];
        foreach (is_array($env) ? $env : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * The notification key (HKDF-SHA256 of `SESSION_SECRET`, info
     * `logbook-notify`), or null without a secret: as AppSettings and
     * SecretBox derive it.
     *
     * @param array<string, string> $env
     */
    private function key(array $env): ?string
    {
        $secret = $env['SESSION_SECRET'] ?? '';
        $file = $env['SESSION_SECRET_FILE'] ?? '';
        if ($secret === '' && $file !== '' && is_file($file) && is_readable($file)) {
            $contents = file_get_contents($file);
            $secret = $contents === false ? '' : trim($contents);
        }

        return $secret === '' ? null : hash_hkdf('sha256', $secret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::INFO);
    }

    private static function seal(string $value, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox(trim($value), $nonce, $key));
    }

    private function openSecret(int $userId, string $name, ?string $key): ?string
    {
        $row = $this->fetchRow(sprintf(
            "SELECT value FROM notification_secrets WHERE owner_user_id = %d AND name = '%s'",
            $userId,
            $name,
        ));
        $stored = is_array($row) && is_string($row['value'] ?? null) ? $row['value'] : null;
        if ($key === null || $stored === null || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        try {
            $plain = sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $key,
            );
        } catch (SodiumException) {
            return null;
        }

        return $plain === false ? null : $plain;
    }

    /**
     * @return list<array{0: int, 1: bool}> user id, is admin
     */
    private function users(): array
    {
        $users = [];
        foreach ($this->fetchAll('SELECT id, is_admin FROM users ORDER BY id') as $row) {
            if (is_array($row)) {
                $users[] = [self::int($row['id'] ?? null), self::truthy($row['is_admin'] ?? null)];
            }
        }

        return $users;
    }

    /**
     * @return list<array{0: int, 1: int, 2: array<string, mixed>}> setting id, user id, decoded value
     */
    private function notificationRows(): array
    {
        $rows = [];
        foreach (
            $this->fetchAll(
                "SELECT id, owner_id, value FROM settings WHERE scope = 'user' AND name = '" . self::NOTIFICATIONS . "'",
            ) as $row
        ) {
            if (!is_array($row) || !is_numeric($row['id'] ?? null) || !is_numeric($row['owner_id'] ?? null)) {
                continue;
            }
            $value = is_string($row['value'] ?? null) ? json_decode($row['value'], true) : null;
            /** @var array<string, mixed> $decoded */
            $decoded = is_array($value) ? $value : [];
            $rows[] = [(int) $row['id'], (int) $row['owner_id'], $decoded];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function saveValue(int $id, array $value): void
    {
        $this->execute(
            'UPDATE settings SET value = ? WHERE id = ?',
            [json_encode($value, JSON_THROW_ON_ERROR), $id],
        );
    }

    /**
     * "https://ntfy.example/ntfy/garage" → ["https://ntfy.example/ntfy", "garage"],
     * as NtfyChannel read it before this phase.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function splitTopic(string $url): array
    {
        $trimmed = rtrim(trim($url), '/');
        $slash = strrpos($trimmed, '/');
        if ($slash === false) {
            return [null, null];
        }
        $server = substr($trimmed, 0, $slash);
        $topic = substr($trimmed, $slash + 1);
        $valid = in_array(parse_url($server, PHP_URL_SCHEME), ['http', 'https'], true)
            && is_string(parse_url($server, PHP_URL_HOST))
            && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $topic) === 1;

        return $valid ? [$server, $topic] : [null, null];
    }

    private static function httpUrl(string $url): ?string
    {
        $url = trim($url);

        return $url !== '' && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? rtrim($url, '/') : null;
    }

    private static function priority(string $value): int
    {
        $priority = filter_var(trim($value), FILTER_VALIDATE_INT);

        return is_int($priority) ? max(0, min(10, $priority)) : self::DEFAULT_PRIORITY;
    }

    private static function nonEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
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
