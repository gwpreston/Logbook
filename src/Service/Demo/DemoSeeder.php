<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Logbook\Support\Config\AppSettings;
use Phinx\Db\Adapter\AdapterFactory;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Puts the sample data (db/seeds/DemoDataSeeder.php) into the database the
 * app is connected to, on the app's own connection: Phinx's adapter is given
 * the same PDO, so the caller's transaction covers the seeding and a failure
 * rolls all of it back (spec.md §7.36).
 */
final readonly class DemoSeeder implements SampleData
{
    public function __construct(
        private Connection $connection,
        private AppSettings $settings,
    ) {
    }

    public function seed(string $password, DateTimeImmutable $today): void
    {
        $file = $this->settings->rootDir . '/db/seeds/DemoDataSeeder.php';
        if (!class_exists(\DemoDataSeeder::class, false)) {
            if (!is_file($file)) {
                throw new RuntimeException('The sample data is missing from this installation (db/seeds/DemoDataSeeder.php).');
            }
            require_once $file;
        }

        $pdo = $this->connection->getNativeConnection();
        if (!$pdo instanceof \PDO) {
            throw new RuntimeException('The demo can only be seeded on a PDO connection.');
        }

        $environment = $this->settings->database->toPhinxEnvironment();
        $adapter = AdapterFactory::instance()->getAdapter(
            (string) $environment['adapter'],
            $environment + ['connection' => $pdo],
        );

        $seeder = (new \DemoDataSeeder())->forDemo($password, $today, $this->settings->uploadPath);
        $seeder->setAdapter($adapter);
        $seeder->setInput(new ArrayInput([]));
        $seeder->setOutput(new NullOutput());
        $seeder->run();
    }
}
