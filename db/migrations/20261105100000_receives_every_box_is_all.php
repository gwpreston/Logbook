<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 37 (spec.md §7.11 *What each channel receives*; #268, #271): a
 * *Receives* with every box offered to its owner ticked means all, stored
 * as null, so a category added later reaches it. Lists saved that way under
 * v3.3.0 are converted: a personal channel's `categories` and email's
 * `email_categories` in the user's `notifications` setting. A list that
 * leaves a box out is kept as it is.
 *
 * Data only. Rolling back leaves the converted ones as all, which is what
 * every box ticked meant, so there is nothing to undo.
 */
final class ReceivesEveryBoxIsAll extends AbstractMigration
{
    private const array MEMBER = ['due', 'overdue', 'digest', 'price_alerts'];
    private const array ADMIN = ['due', 'overdue', 'digest', 'price_alerts', 'job_failures'];

    public function up(): void
    {
        $admins = [];
        foreach ($this->fetchAll('SELECT id, is_admin FROM users') as $row) {
            if (is_array($row) && is_numeric($row['id'] ?? null)) {
                $admins[(int) $row['id']] = self::truthy($row['is_admin'] ?? null);
            }
        }

        $channels = $this->fetchAll('SELECT id, user_id, categories FROM notification_channels WHERE categories IS NOT NULL');
        foreach ($channels as $row) {
            if (!is_array($row) || !is_numeric($row['id'] ?? null) || !is_numeric($row['user_id'] ?? null)) {
                continue;
            }
            if (self::isEveryBox($row['categories'] ?? null, $admins[(int) $row['user_id']] ?? false)) {
                $this->execute('UPDATE notification_channels SET categories = NULL WHERE id = ?', [(int) $row['id']]);
            }
        }

        $preferences = $this->fetchAll(
            "SELECT id, owner_id, value FROM settings WHERE scope = 'user' AND name = 'notifications'",
        );
        foreach ($preferences as $row) {
            if (!is_array($row) || !is_numeric($row['id'] ?? null) || !is_numeric($row['owner_id'] ?? null)) {
                continue;
            }
            $value = is_string($row['value'] ?? null) ? json_decode($row['value'], true) : null;
            $isAdmin = $admins[(int) $row['owner_id']] ?? false;
            if (!is_array($value) || !self::isEveryBox($value['email_categories'] ?? null, $isAdmin)) {
                continue;
            }
            unset($value['email_categories']);
            $this->execute(
                'UPDATE settings SET value = ? WHERE id = ?',
                [json_encode($value, JSON_THROW_ON_ERROR), (int) $row['id']],
            );
        }
    }

    public function down(): void
    {
        // Nothing to undo: see the class comment.
    }

    /**
     * Whether a stored comma list (`due,overdue`) holds every category
     * offered to an admin, or to a member.
     */
    private static function isEveryBox(mixed $stored, bool $isAdmin): bool
    {
        if (!is_string($stored)) {
            return false;
        }
        $ticked = array_map('trim', explode(',', $stored));

        return array_diff($isAdmin ? self::ADMIN : self::MEMBER, $ticked) === [];
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
