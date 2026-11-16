<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Every test starts with no time limit (K210). Actions that run a job
 * (`LongRequest::allow()`, Ask, Scan, OCR settings) call set_time_limit(),
 * and in the suite they run inside PHPUnit's own process, so the limit
 * outlived the test that set it. On Windows PHP counts that limit in
 * wall-clock time, so a later slow test on SQLite was killed part-way
 * ("Maximum execution time of 60 seconds exceeded") and the run stopped
 * with no summary. Registered in phpunit.xml.dist.
 */
final class NoTimeLimit implements Extension, FinishedSubscriber
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this);
    }

    public function notify(Finished $event): void
    {
        self::reset();
    }

    /** Lift any time limit the last test set; 0 is the CLI's own default. */
    public static function reset(): void
    {
        set_time_limit(0);
    }
}
