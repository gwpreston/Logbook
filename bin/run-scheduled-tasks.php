<?php

declare(strict_types=1);

/*
 * A scheduler pass (spec.md §5 *Jobs*, §7.30): every job that is due —
 * reminders and the monthly digest every pass, cleanup hourly, backups as
 * scheduled — each recorded on Settings → Jobs.
 *
 * Bare-PHP install: run it every 15 minutes (SCHEDULER_INTERVAL) from cron,
 * as the web server user:
 *
 *   *\/15 * * * *  www-data  cd /var/www/logbook && php bin/run-scheduled-tasks.php
 *
 * No cron? Settings → Jobs → *How jobs run* has two fallbacks: on page
 * visits, and a secret URL an external service calls.
 *
 * Docker: the entrypoint runs it every SCHEDULER_INTERVAL seconds (with
 * LOGBOOK_SCHEDULER_TRIGGER=docker, so its runs are labelled); no host
 * cron needed.
 *
 * Safe to run as often as you like: locks stop passes and jobs
 * overlapping, and each reminder is sent only once per status. Prints
 * nothing unless given -v (cron mails any output; the runs keep it).
 * Exit code: 0 ok, 1 a job failed or partly failed (see Settings → Jobs),
 * 2 another pass holds the lock.
 */

use Logbook\Domain\Job\JobTrigger;
use Logbook\Kernel;
use Logbook\Service\Scheduler\ScheduledTasks;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = Kernel::settings();

// The app (not just the container): notifications link to routes.
$container = Kernel::createApp($settings)->getContainer();

// The command line, as strings (the $argv global is not guaranteed to be set).
$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));
$verbose = in_array('-v', $args, true) || in_array('--verbose', $args, true);

$trigger = getenv('LOGBOOK_SCHEDULER_TRIGGER') === 'docker' ? JobTrigger::Docker : JobTrigger::Cron;

$tasks = $container->get(ScheduledTasks::class);
assert($tasks instanceof ScheduledTasks);
$summary = $tasks->run($trigger, $verbose ? static function (string $line): void {
    fwrite(STDOUT, $line . "\n");
} : null);

if ($summary->wasLocked) {
    fwrite(STDERR, "Another scheduled-task run is in progress; skipping.\n");
    exit(2);
}
if ($verbose) {
    fwrite(STDOUT, ucfirst($summary->describe()) . ".\n");
}

exit($summary->failed() ? 1 : 0);
