<?php

declare(strict_types=1);

use Logbook\Support\Database\Migration;

/**
 * The accent colour preference (spec.md §6 User, §8): a short code, `blue`
 * unless the owner picks another. Existing accounts get the default.
 */
final class AddAccentToUsers extends Migration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('accent', 'string', ['limit' => 16, 'null' => false, 'default' => 'blue', 'after' => 'theme'])
            ->update();
    }
}
