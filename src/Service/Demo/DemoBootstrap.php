<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

use Logbook\Service\Jobs\JobLocks;
use Logbook\Support\Config\AppSettings;
use Psr\Log\LoggerInterface;

/**
 * The start of the app with `DEMO_MODE` set (spec.md §7.36): from the Docker
 * entrypoint (`bin/demo-seed.php`) after the migrations, and from the web
 * start path for a bare-PHP install. An empty database is seeded and marked;
 * a refused demo is logged at error level; anything else is left alone.
 */
final readonly class DemoBootstrap
{
    private const string LOCK = 'demo_seed';
    /** Seconds between the web path's repeats of the same error line. */
    private const int LOG_EVERY = 3600;

    public function __construct(
        private DemoMode $mode,
        private DemoResetter $resetter,
        private JobLocks $locks,
        private AppSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Seeds the demo when the database is empty, and says so when `DEMO_MODE`
     * is refused. False when another process is seeding right now.
     *
     * @param bool $throttle repeat a refusal's log line at most hourly (the web path, which runs on every request)
     */
    public function ensure(bool $throttle = false): bool
    {
        if (!$this->settings->demo->enabled) {
            return true;
        }

        $status = $this->mode->status();
        if ($status->state === DemoState::Refused && $status->refusal !== null) {
            $this->logRefusal($status->refusal, $throttle);

            return true;
        }
        if ($status->state !== DemoState::NeedsSeed) {
            return true;
        }

        $lock = $this->locks->acquire(self::LOCK);
        if ($lock === null) {
            return false;
        }
        try {
            // Another process may have seeded it while this one waited for the lock.
            $this->mode->forget();

            return $this->resetter->seedFirst() || $this->mode->state() !== DemoState::NeedsSeed;
        } finally {
            $this->locks->release($lock);
        }
    }

    private function logRefusal(DemoRefusal $refusal, bool $throttle): void
    {
        if ($throttle) {
            $stamp = $this->settings->cacheDir . '/demo-refused.stamp';
            if (is_file($stamp) && time() - (int) filemtime($stamp) < self::LOG_EVERY) {
                return;
            }
            @mkdir(dirname($stamp), 0775, true);
            @touch($stamp);
        }
        $this->logger->error(match ($refusal) {
            DemoRefusal::RealData => 'DEMO_MODE is set, but this database holds real data. '
                . 'Demo mode is off and nothing was changed. Remove the setting.',
            DemoRefusal::Password => 'DEMO_MODE is set, but DEMO_PASSWORD is missing or not 8 to 1024 characters. '
                . 'Demo mode is off and nothing was changed.',
        });
    }
}
