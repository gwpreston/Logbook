<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The accent colour preference (spec.md §6 User, §8): a short code, `blue`
 * unless the owner picks another. Existing accounts get the default.
 */
final class AddAccentToUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('accent', 'string', ['limit' => 16, 'null' => false, 'default' => 'blue', 'after' => 'theme'])
            ->update();
    }
}
