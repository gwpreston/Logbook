<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Accounts (spec.md §6 User, Invitation; §7.9, Phase 33.1): one confirmed
 * email address per user, an address waiting for its confirmation link, an
 * avatar, and the address an `email` confirmation link is for.
 *
 * Each user's reminder address (`email` in their `notifications` setting)
 * moves to `users.email`, counted as confirmed (#163: they already received
 * reminders there), and leaves the setting. Rolling back moves it back,
 * drops `email_pending` and deletes `email` links, which an older version
 * cannot open. Avatar files stay on disk; an older version ignores them.
 *
 * Explicit up/down rather than change(): rows move both ways.
 */
final class AddAccountEmailAndAvatar extends AbstractMigration
{
    private const string NOTIFICATIONS = 'notifications';

    public function up(): void
    {
        $this->table('users')
            ->addColumn('email', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('email_pending', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('avatar_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('avatar_updated_at', 'datetime', ['null' => true])
            ->addIndex(['email'], ['name' => 'users_email_idx'])
            ->update();

        $this->table('invitations')
            ->addColumn('email', 'string', ['limit' => 254, 'null' => true])
            ->update();

        foreach ($this->notificationRows() as [$id, $userId, $value]) {
            $email = is_string($value['email'] ?? null) ? mb_strtolower(trim($value['email'])) : '';
            unset($value['email']);
            $this->saveValue($id, $value);
            if ($email !== '' && mb_strlen($email) <= 254 && $this->userExists($userId)) {
                $this->execute('UPDATE users SET email = ? WHERE id = ?', [$email, $userId]);
            }
        }
    }

    public function down(): void
    {
        $emails = [];
        foreach ($this->fetchAll('SELECT id, email FROM users WHERE email IS NOT NULL') as $row) {
            if (is_array($row) && is_numeric($row['id'] ?? null) && is_string($row['email'] ?? null)) {
                $emails[(int) $row['id']] = $row['email'];
            }
        }
        foreach ($this->notificationRows() as [$id, $userId, $value]) {
            if (isset($emails[$userId])) {
                $value['email'] = $emails[$userId];
                $this->saveValue($id, $value);
                unset($emails[$userId]);
            }
        }
        // A user with an address but no stored preferences gets a row of their own.
        $now = gmdate('Y-m-d H:i:s');
        foreach ($emails as $userId => $email) {
            $this->table('settings')->insert([
                'scope' => 'user',
                'owner_id' => $userId,
                'name' => self::NOTIFICATIONS,
                'value' => json_encode(['email' => $email], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ])->saveData();
        }

        $this->execute('DELETE FROM invitations WHERE kind = ?', ['email']);
        $this->table('invitations')->removeColumn('email')->update();

        $this->table('users')->removeIndexByName('users_email_idx')->update();
        $this->table('users')
            ->removeColumn('email')
            ->removeColumn('email_pending')
            ->removeColumn('avatar_path')
            ->removeColumn('avatar_updated_at')
            ->update();
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

    private function userExists(int $userId): bool
    {
        return $this->fetchRow('SELECT id FROM users WHERE id = ' . $userId) !== false;
    }
}
