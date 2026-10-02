<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * A Monolog handler on the app logger: while a job runs, every record
 * (debug included, whatever LOG_LEVEL) also becomes a line of that run's output, so the
 * output shows what the job's services logged (each notification sent,
 * each failure). Outside a run it does nothing. One instance is shared by
 * the logger and JobRunner.
 */
final class RunCapture extends AbstractProcessingHandler
{
    private ?RunLog $current = null;

    public function __construct()
    {
        parent::__construct(Level::Debug, true);
    }

    public function begin(RunLog $log): void
    {
        $this->current = $log;
    }

    public function end(): void
    {
        $this->current = null;
    }

    public function isCapturing(): bool
    {
        return $this->current !== null;
    }

    /**
     * Nothing outside a run, so debug records cost nothing app-wide.
     */
    public function isHandling(LogRecord $record): bool
    {
        return $this->current !== null && parent::isHandling($record);
    }

    protected function write(LogRecord $record): void
    {
        $this->current?->add(strtolower($record->level->getName()), $record->message);
    }
}
