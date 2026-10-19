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
        $running = $this->start($command, $timeout, $env, $maxOutput);

        return $running instanceof RunningProcess ? $running->wait() : $running;
    }

    /**
     * Starts it and returns at once; RunningProcess::wait() collects it,
     * with $timeout counted from now. A process that can't start is its
     * result straight away.
     *
     * @param non-empty-list<string> $command
     * @param array<string, string> $env added to the current environment
     */
    public function start(
        array $command,
        float $timeout,
        array $env = [],
        int $maxOutput = self::MAX_OUTPUT,
    ): RunningProcess|ProcessResult {
        if (!function_exists('proc_open')) {
            return new ProcessResult(-1, '', 'proc_open is disabled');
        }
        $out = tmpfile();
        $err = tmpfile();
        if ($out === false || $err === false) {
            return new ProcessResult(-1, '', 'no temporary file');
        }
        $environment = $env === [] ? null : array_merge(getenv(), $env);
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $err], $pipes, null, $environment);
        if (!is_resource($process)) {
            fclose($out);
            fclose($err);

            return new ProcessResult(-1, '', 'could not start');
        }

        return new RunningProcess($process, $out, $err, microtime(true), $timeout, $maxOutput);
    }
}
