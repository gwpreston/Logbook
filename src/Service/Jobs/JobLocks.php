<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Logbook\Support\Config\AppSettings;
use RuntimeException;

/**
 * One `flock` file per job, and one for a scheduler pass (spec.md §5
 * *Jobs*, *Locks*), under the cache directory: `var/` itself may not be
 * writable (the Docker image), and flock() needs no write access, so a
 * lock file left by another user (say, root via `docker exec`) still
 * works.
 */
final readonly class JobLocks
{
    public const string PASS = 'pass';

    public function __construct(private AppSettings $settings)
    {
    }

    /**
     * Take the lock without waiting: a handle to release, or null when
     * another process holds it.
     *
     * @return resource|null
     */
    public function acquire(string $name)
    {
        // The pass keeps the old task's lock file, so a run of an older
        // release (mid-upgrade) and this one never overlap.
        $dir = $this->settings->cacheDir . ($name === self::PASS ? '' : '/locks');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create the lock directory "%s".', $dir));
        }
        $file = $dir . '/' . ($name === self::PASS ? 'scheduled-tasks.lock' : 'job-' . $name . '.lock');
        $handle = @fopen($file, is_file($file) ? 'r' : 'c');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot open the lock file "%s".', $file));
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    public function release($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
