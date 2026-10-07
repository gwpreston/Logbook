<?php

declare(strict_types=1);

/*
 * Phinx configuration. Reads the same environment variables as the app, so
 * migrations always target the database the app uses.
 *
 *   development / production  DB_*       (the app's database)
 *   testing                   TEST_DB_*  (the PHPUnit database; default SQLite)
 *
 * Select the engine with DB_DRIVER / TEST_DB_DRIVER = pgsql | mysql | sqlite.
 * Every migration must migrate AND roll back cleanly on PostgreSQL and MySQL.
 */

use Logbook\Kernel;
use Logbook\Support\Config\DatabaseConfig;
use Logbook\Support\Config\Env;

require __DIR__ . '/vendor/autoload.php';

$env = Env::fromSystem(__DIR__ . '/.env');
// The few variables a data migration reads, as the app sees them (`.env` included): the notification key
// and, once, the channel variables Phase 36.2 imports (db/migrations/*_create_notification_channels.php).
$migrationEnv = ['logbook_env' => array_intersect_key($env->all(), array_flip([
    'SESSION_SECRET', 'SESSION_SECRET_FILE',
    'NTFY_URL', 'NTFY_TOKEN', 'GOTIFY_URL', 'GOTIFY_TOKEN', 'GOTIFY_PRIORITY',
]))];
$app = DatabaseConfig::fromEnv($env, Kernel::rootDir())->toPhinxEnvironment() + $migrationEnv;
$testing = DatabaseConfig::fromEnv($env, Kernel::rootDir(), 'TEST_DB_', 'var/testing.sqlite')->toPhinxEnvironment()
    + $migrationEnv;

return [
    'paths' => [
        'migrations' => __DIR__ . '/db/migrations',
        'seeds' => __DIR__ . '/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => $app,
        'production' => $app,
        'testing' => $testing,
    ],
    'version_order' => 'creation',
];
