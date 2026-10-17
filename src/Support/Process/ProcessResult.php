<?php

declare(strict_types=1);

namespace Logbook\Support\Process;

final readonly class ProcessResult
{
    public function __construct(
        /** The exit code; -1 when it couldn't start or was killed. */
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut = false,
    ) {
    }

    public function ok(): bool
    {
        return !$this->timedOut && $this->exitCode === 0;
    }
}
