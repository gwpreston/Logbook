<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps what was logged, as [level, message].
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [is_string($level) ? $level : 'log', (string) $message];
    }
}
