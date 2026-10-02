<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Closure;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * A job run's output (spec.md §7.30): every line the app logs while the
 * run is active (RunCapture), redacted, kept, handed to a sink (the CLI
 * prints it), and written to the run's row as it goes, at most once a
 * second, so the run page can follow it.
 */
final class RunLog
{
    /** @var list<string> */
    private array $lines = [];
    private int $flushedAt = 0;
    private bool $dirty = false;

    /**
     * @param Closure(string): void $store writes the output so far to the row
     * @param (Closure(string): void)|null $sink
     */
    public function __construct(
        private readonly OutputRedactor $redactor,
        private readonly ClockInterface $clock,
        private readonly Closure $store,
        private readonly ?Closure $sink = null,
    ) {
    }

    public function add(string $level, string $message): void
    {
        $line = sprintf(
            '[%s] %s%s',
            $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('H:i:s'),
            in_array($level, ['info', 'notice', 'debug'], true) ? '' : strtoupper($level) . ': ',
            // Invalid UTF-8 would be refused by PostgreSQL and MySQL.
            $this->redactor->redact(mb_scrub($message, 'UTF-8')),
        );
        $this->lines[] = $line;
        $this->dirty = true;
        if ($this->sink !== null) {
            ($this->sink)($line);
        }
        if ($this->clock->now()->getTimestamp() > $this->flushedAt) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (!$this->dirty) {
            return;
        }
        $this->flushedAt = $this->clock->now()->getTimestamp();
        $this->dirty = false;
        try {
            ($this->store)($this->output());
        } catch (Throwable) {
            // Progress only: the output is stored in full when the run finishes.
        }
    }

    public function output(): string
    {
        return OutputCap::join($this->lines);
    }
}
