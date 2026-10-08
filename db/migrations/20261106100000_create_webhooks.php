<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Entry webhooks (spec.md §6 Webhook and WebhookDelivery, §7.20
 * *Webhooks*; Phase 39.3, decided 2026-10-08, #285, #288, #289, #292–#294).
 *
 * - `webhooks`: a user's endpoints. `secret` is sealed (§7.25, HKDF info
 *   `logbook-webhook`) and null after a restore until the user makes a
 *   new one; `failures` counts consecutive failed attempts (#293);
 *   `notice_pending` marks a webhook paused for failures whose user has
 *   not been told yet (#294).
 * - `webhook_deliveries`: what is queued, by the services that change
 *   entries, for the `webhooks` job; removed 7 days after `created_at`.
 *
 * Both go with their user (and a delivery with its webhook). Rolling back
 * drops both tables.
 */
final class CreateWebhooks extends AbstractMigration
{
    public function up(): void
    {
        $this->table('webhooks')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('url', 'string', ['limit' => 500, 'null' => false])
            ->addColumn('events', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('secret', 'text', ['null' => true])
            ->addColumn('paused', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('paused_reason', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('last_status', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('last_attempt_at', 'datetime', ['null' => true])
            ->addColumn('last_error', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('failures', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('notice_pending', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['user_id'], ['name' => 'webhooks_user_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'webhooks_user_fk',
            ])
            ->create();

        $this->table('webhook_deliveries')
            ->addColumn('webhook_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('event', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('payload', 'json', ['null' => false])
            ->addColumn('attempts', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('next_attempt_at', 'datetime', ['null' => true])
            ->addColumn('delivered_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['next_attempt_at'], ['name' => 'webhook_deliveries_next_idx'])
            ->addIndex(['webhook_id'], ['name' => 'webhook_deliveries_webhook_idx'])
            ->addForeignKey('webhook_id', 'webhooks', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'webhook_deliveries_webhook_fk',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('webhook_deliveries')->drop()->save();
        $this->table('webhooks')->drop()->save();
    }
}
