<?php

declare(strict_types=1);

namespace Logbook\Support\Process;

/**
 * A process ProcessRunner::start() left running, so other work (a model's
 * request, spec.md §7.27 *The cross-check*) can go on while it does. Its
 * timeout counts from when it started; wait() collects it once.
 */
final class RunningProcess
{
    private ?ProcessResult $result = null;

    /**
     * @param resource $process
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private $process,
        private $out,
        private $err,
        private readonly float $started,
        private readonly float $timeout,
        private readonly int $maxOutput,
    ) {
    }

    public function wait(): ProcessResult
    {
        if ($this->result !== null) {
            return $this->result;
        }
        try {
            $status = proc_get_status($this->process);
            while ($status['running']) {
                if (microtime(true) - $this->started > $this->timeout) {
                    proc_terminate($this->process, 9);
                    proc_close($this->process);

                    return $this->result = new ProcessResult(-1, '', '', true);
                }
                usleep(20_000);
                $status = proc_get_status($this->process);
            }
            $code = $status['exitcode'];
            proc_close($this->process);

            $out = self::read($this->out, $this->maxOutput);

            return $this->result = new ProcessResult($code, $out, self::read($this->err, 65536));
        } finally {
            fclose($this->out);
            fclose($this->err);
        }
    }

    /**
     * Stop a run nobody will collect, and wait until it has ended, so it
     * writes nothing after this returns (K116). Does nothing once collected.
     */
    public function stop(): void
    {
        if ($this->result === null && is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
            fclose($this->out);
            fclose($this->err);
            $this->result = new ProcessResult(-1, '', '', true);
        }
    }

    public function __destruct()
    {
        // Never left behind: a run nobody collected is stopped.
        $this->stop();
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
