<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Kernel;
use Phinx\Console\PhinxApplication;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs Phinx commands against the `testing` environment in-process, with a console wide enough
 * that phinx never wraps a message (Phase 54.21, K225, #647).
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

        // Symfony Console wraps phinx's messages at the console's width, read from COLUMNS first;
        // a narrow terminal would split a message the tests look for, so it is wide while phinx
        // runs and put back after (unset again if it was unset).
        $columns = getenv('COLUMNS');
        putenv('COLUMNS=1000');
        try {
            $exitCode = $application->run($input, $output);
        } finally {
            putenv($columns === false ? 'COLUMNS' : 'COLUMNS=' . $columns);
        }
        $text = $output->fetch();

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf("phinx %s failed (exit %d):\n%s", $command, $exitCode, $text));
        }

        return $text;
    }
}
