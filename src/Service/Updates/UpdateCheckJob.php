<?php

declare(strict_types=1);

namespace Logbook\Service\Updates;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Service\Jobs\JobContext;
use Logbook\Service\Jobs\JobResult;
use Logbook\Service\Jobs\TimedJob;
use Logbook\Support\Version\InstalledVersion;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `update_check` (spec.md §7.31): once a day at the install's own minute
 * (UTC), while *Check for updates* is on, asks GitHub for the latest
 * release. Off, it is never due and a run by hand sends nothing. GitHub's
 * errors are an `ok` run with the error as its summary, so they never
 * raise a failure alert (#114); only an error in Logbook fails the run.
 * Registered only while `UPDATE_CHECK_ALLOWED` is on.
 */
final readonly class UpdateCheckJob implements TimedJob
{
    public const string NAME = 'update_check';
    public const int INTERVAL = 86400;

    public function __construct(
        private UpdateSettings $settings,
        private ReleaseChecker $checker,
        private UpdateMessages $messages,
        private InstalledVersion $installed,
        private TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function interval(): ?int
    {
        return $this->settings->checking() ? self::INTERVAL : null;
    }

    /**
     * Today's minute once today's run hasn't happened, else tomorrow's;
     * never before a rate limit's wait is over.
     */
    public function dueAt(?DateTimeImmutable $lastStarted, DateTimeImmutable $now): DateTimeImmutable
    {
        $slot = $now->setTimezone(new DateTimeZone('UTC'))->setTime(0, $this->settings->minute());
        if ($lastStarted !== null && $lastStarted >= $slot) {
            $slot = $slot->modify('+1 day');
        }
        $retryAt = $this->settings->status()->retryAt;

        return $retryAt !== null && $retryAt > $slot ? $retryAt : $slot;
    }

    public function run(JobContext $context): JobResult
    {
        if (!$this->settings->checking()) {
            $context->logger->info('Checking for updates is off; nothing sent.');

            return JobResult::ok($this->translator->trans('updates.summary.off'));
        }

        $status = $this->checker->check($this->settings->status());
        $this->settings->saveStatus($status);

        return JobResult::ok($this->messages->summary(
            $status,
            $this->installed->semVer(),
            $this->installed->version,
        ));
    }
}
