<?php

declare(strict_types=1);

namespace Logbook\Support\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Logbook\Support\Cache\RequestReads;
use Logbook\Support\Config\DatabaseConfig;
use Logbook\Support\Config\DatabaseDriver;

/**
 * Builds the shared DBAL connection. Connecting is lazy: nothing touches the
 * database until the first query, so a down database never breaks boot.
 */
final class ConnectionFactory
{
    /**
     * @param RequestReads|null $reads the app's page-request reads, which a write
     *                                 must make forget what it changed; null for a
     *                                 connection that has none (a command)
     */
    public static function create(DatabaseConfig $config, ?RequestReads $reads = null): Connection
    {
        $middlewares = [new SessionInitMiddleware($config->driver)];
        if ($reads !== null) {
            $middlewares[] = new ForgetWrittenReadsMiddleware($reads);
        }
        $configuration = (new Configuration())->setMiddlewares($middlewares);

        $params = match ($config->driver) {
            DatabaseDriver::Sqlite => $config->name === ':memory:'
                ? ['driver' => 'pdo_sqlite', 'memory' => true]
                : ['driver' => 'pdo_sqlite', 'path' => $config->name],
            DatabaseDriver::Mysql => [
                'driver' => 'pdo_mysql',
                'host' => $config->host,
                'port' => (int) $config->port,
                'dbname' => $config->name,
                'user' => $config->user,
                'password' => $config->password,
                'charset' => 'utf8mb4',
            ],
            DatabaseDriver::Pgsql => [
                'driver' => 'pdo_pgsql',
                'host' => $config->host,
                'port' => (int) $config->port,
                'dbname' => $config->name,
                'user' => $config->user,
                'password' => $config->password,
                'charset' => 'utf8',
            ],
        };

        return DriverManager::getConnection($params, $configuration);
    }
}
