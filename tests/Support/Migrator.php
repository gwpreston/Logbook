<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Kernel;
use Phinx\Console\PhinxApplication;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs Phinx commands against the `testing` environment in-process.
 */
final class Migrator
{
    /**
     * @param array<string, string|list<string>> $options e.g. ['--target' => '0'], ['--seed' => ['DemoDataSeeder']]
     */
    public static function run(string $command, array $options = []): string
    {
        $application = new PhinxApplication();
        $application->setAutoExit(false);

        $output = new BufferedOutput();
        $input = new ArrayInput([
            'command' => $command,
            '--configuration' => Kernel::rootDir() . '/phinx.php',
            '--environment' => 'testing',
            '--no-interaction' => true,
        ] + $options);

        $exitCode = $application->run($input, $output);
        $text = $output->fetch();

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf("phinx %s failed (exit %d):\n%s", $command, $exitCode, $text));
        }

        return $text;
    }
}
