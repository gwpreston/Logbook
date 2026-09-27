<?php

declare(strict_types=1);

/*
 * Scheduled-task runner: syncs every owner's reminders, sends the ones that
 * have become due or overdue through their notification channels, and the
 * monthly digest (spec.md §7.6, §7.11).
 *
 * Bare-PHP install: run it every 15 minutes from cron, as the web server user:
 *
 *   *\/15 * * * *  www-data  cd /var/www/logbook && php bin/run-scheduled-tasks.php
 *
 * Docker: the entrypoint runs it every SCHEDULER_INTERVAL seconds; no host
 * cron needed.
 *
 * Safe to run as often as you like: a lock file stops runs overlapping, and
 * each reminder is sent only once per status. Prints nothing unless given -v
 * (the summary always goes to the log). Exit code: 0 ok, 1 an owner's run
 * failed (see the log), 2 another run holds the lock.
 */

use Logbook\Kernel;
use Logbook\Service\Scheduler\ScheduledTasks;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = Kernel::settings();

// The app (not just the container): notifications link to routes.
$container = Kernel::createApp($settings)->getContainer();

// var/cache: writable by the web server user on every install (var/ itself
// may not be, e.g. in the Docker image).
$lockDir = $settings->cacheDir;
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0775, true);
}
// flock() needs no write access, so a lock file left by a run as another
// user (say, root via `docker exec`) is still usable.
$lockFile = $lockDir . '/scheduled-tasks.lock';
$lock = fopen($lockFile, is_file($lockFile) ? 'r' : 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another scheduled-task run is in progress; skipping.\n");
    exit(2);
}

try {
    $tasks = $container->get(ScheduledTasks::class);
    assert($tasks instanceof ScheduledTasks);
    $summary = $tasks->run();
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

// Quiet by default (cron mails any output); the summary is always logged.
if (in_array('-v', $argv, true) || in_array('--verbose', $argv, true)) {
    fwrite(STDOUT, ucfirst($summary->describe()) . ".\n");
}

exit($summary->failures === 0 ? 0 : 1);
