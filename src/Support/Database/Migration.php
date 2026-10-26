<?php

declare(strict_types=1);

namespace Logbook\Support\Database;

use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\MigrationInterface;

/**
 * Base class of every migration: a table change on SQLite that Phinx does by
 * rebuilding the table keeps the table's id counter (spec.md §6.1, K63).
 */
abstract class Migration extends AbstractMigration
{
    public function setAdapter(AdapterInterface $adapter): MigrationInterface
    {
        return parent::setAdapter($adapter instanceof SequenceKeepingAdapter ? $adapter : new SequenceKeepingAdapter($adapter));
    }
}
