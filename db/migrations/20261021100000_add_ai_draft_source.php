<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Where a draft came from (spec.md §6 AiDraft; §7.28, Phase 26.5): `ask`
 * for Ask Logbook's cards, `mcp` for a draft tool called by an MCP client,
 * kept 7 days and listed on the dashboard. Every existing row is an Ask
 * draft. A plain string column, as the other codes are.
 *
 * Rolling back drops the column; MCP drafts left then are ordinary
 * drafts until they expire.
 */
final class AddAiDraftSource extends AbstractMigration
{
    public function up(): void
    {
        $this->table('ai_drafts')
            ->addColumn('source', 'string', ['limit' => 8, 'null' => false, 'default' => 'ask'])
            ->addIndex(['user_id', 'source'], ['name' => 'ai_drafts_user_source_idx'])
            ->update();
    }

    public function down(): void
    {
        $this->table('ai_drafts')
            ->removeIndexByName('ai_drafts_user_source_idx')
            ->removeColumn('source')
            ->update();
    }
}
