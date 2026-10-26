<?php

declare(strict_types=1);

namespace Logbook\Support\Database;

use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\ProxyAdapter;
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
        // Rolling back a change() migration records its commands on a proxy, then runs the
        // reversed ones on the proxy's inner adapter: that one is wrapped too.
        if ($adapter instanceof ProxyAdapter) {
            $adapter->setAdapter(self::wrap($adapter->getAdapter()));

            return parent::setAdapter($adapter);
        }

        return parent::setAdapter(self::wrap($adapter));
    }

    private static function wrap(AdapterInterface $adapter): AdapterInterface
    {
        return $adapter instanceof SequenceKeepingAdapter ? $adapter : new SequenceKeepingAdapter($adapter);
    }
}
