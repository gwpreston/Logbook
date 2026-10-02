<?php

declare(strict_types=1);

/*
 * Run one background job now (spec.md §7.30), whether or not it is due:
 * the same run *Run now* makes on Settings → Jobs, recorded there with the
 * trigger `manual`.
 *
 *   php bin/run-job.php <job>     # reminders | digest | cleanup | backup
 *   php bin/run-job.php --list    # the jobs and their schedules
 *
 * Run it as the web server user (Docker: docker compose exec -u www-data
 * app php bin/run-job.php reminders). It prints the run's lines as they
 * come, redacted as stored.
 *
 * Exit code: 0 ok or partly ok, 1 failed, 2 already running, 3 usage.
 */

use Logbook\Domain\Job\JobStatus;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Kernel;
use Logbook\Service\Jobs\JobRegistry;
use Logbook\Service\Jobs\JobRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = Kernel::settings();
$container = Kernel::createApp($settings)->getContainer();

$registry = $container->get(JobRegistry::class);
assert($registry instanceof JobRegistry);

// The command line, as strings (the $argv global is not guaranteed to be set).
$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));
$name = $args[1] ?? '';

if ($name === '--list') {
    foreach ($registry->all() as $job) {
        $interval = $job->interval();
        fwrite(STDOUT, sprintf(
            "%-10s %s\n",
            $job->name(),
            match (true) {
                $interval === null => 'manual only',
                $interval === 0 => 'every pass',
                default => sprintf('every %d s', $interval),
            },
        ));
    }
    exit(0);
}

$job = $registry->get($name);
if ($job === null) {
    $names = implode(' | ', array_map(static fn ($j): string => $j->name(), $registry->all()));
    fwrite(STDERR, "Usage: php bin/run-job.php <job> | --list   (jobs: {$names})\n");
    exit(3);
}

$runner = $container->get(JobRunner::class);
assert($runner instanceof JobRunner);
$run = $runner->run($job, JobTrigger::Manual, null, static function (string $line): void {
    fwrite(STDOUT, $line . "\n");
});

if ($run->status === JobStatus::SkippedLocked) {
    exit(2);
}
fwrite(STDOUT, sprintf("%s: %s\n", $run->status->value, $run->summary ?? ''));

exit($run->status === JobStatus::Failed ? 1 : 0);
