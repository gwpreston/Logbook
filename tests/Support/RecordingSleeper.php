<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Support\Clock\Sleeper;

/**
 * Stands in for waiting: keeps how long each wait would have been.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    public array $slept = [];

    public function sleep(float $seconds): void
    {
        $this->slept[] = $seconds;
    }
}
