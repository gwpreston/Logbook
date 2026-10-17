<?php

declare(strict_types=1);

namespace Logbook\Support\Process;

/**
 * Runs a binary from an argument list, never through a shell, with a
 * timeout. Its output goes to temporary files rather than pipes, so a large
 * output can't fill a pipe and stall it until the timeout; at most
 * $maxOutput bytes of each are read back.
 */
final class ProcessRunner
{
    private const int MAX_OUTPUT = 8 * 1024 * 1024;

    /**
     * @param non-empty-list<string> $command
     * @param array<string, string> $env added to the current environment
     */
    public function run(array $command, float $timeout, array $env = [], int $maxOutput = self::MAX_OUTPUT): ProcessResult
    {
        if (!function_exists('proc_open')) {
            return new ProcessResult(-1, '', 'proc_open is disabled');
        }
        $out = tmpfile();
        $err = tmpfile();
        if ($out === false || $err === false) {
            return new ProcessResult(-1, '', 'no temporary file');
        }

        try {
            $environment = $env === [] ? null : array_merge(getenv(), $env);
            $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $err], $pipes, null, $environment);
            if (!is_resource($process)) {
                return new ProcessResult(-1, '', 'could not start');
            }
            $started = microtime(true);
            $status = proc_get_status($process);
            while ($status['running']) {
                if (microtime(true) - $started > $timeout) {
                    proc_terminate($process, 9);
                    proc_close($process);

                    return new ProcessResult(-1, '', '', true);
                }
                usleep(20_000);
                $status = proc_get_status($process);
            }
            $code = $status['exitcode'];
            proc_close($process);

            return new ProcessResult($code, self::read($out, $maxOutput), self::read($err, 65536));
        } finally {
            fclose($out);
            fclose($err);
        }
    }

    /**
     * @param resource $file
     */
    private static function read($file, int $max): string
    {
        rewind($file);

        return (string) stream_get_contents($file, $max);
    }
}
