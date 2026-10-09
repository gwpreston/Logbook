<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MotTestRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Jobs\Job;
use Logbook\Service\Jobs\JobContext;
use Logbook\Service\Jobs\JobResult;
use Logbook\Service\User\UserDirectory;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\UserDisplayScope;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `mot_history` (spec.md §7.30, §7.38 *Refresh*): daily while a provider is
 * enabled. It refreshes each vehicle with MOT history enabled whose MOT
 * falls due between 14 days ahead and 60 days ago in its owner's today
 * (by its latest expiry, or a new car's first MOT due date, #339), not
 * fetched in the last 7 days (#323), signing in once. A `429` stops the
 * run (the rest wait for the next day); refused credentials fail it; any
 * other vehicle's failure is logged and the run goes on. Then, when no
 * call has worked for 80 days or ever (#327, #342), one call that sends no
 * vehicle, so DVSA doesn't revoke the key.
 */
final readonly class MotHistoryJob implements Job
{
    public const string NAME = 'mot_history';
    public const int DAYS_BEFORE = 14;
    public const int DAYS_AFTER = 60;
    public const int REFETCH_DAYS = 7;
    public const int KEEP_ALIVE_DAYS = 80;

    public function __construct(
        private MotHistoryConfig $config,
        private MotHistoryCalls $calls,
        private MotHistoryFetcher $fetcher,
        private MotReview $review,
        private MotTestRepository $tests,
        private VehicleRepository $vehicles,
        private UserDirectory $users,
        private FeatureToggles $features,
        private UserDisplayScope $scope,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function interval(): ?int
    {
        return $this->config->enabled() ? 86400 : null;
    }

    public function run(JobContext $context): JobResult
    {
        $provider = $this->config->provider();
        if ($provider === null) {
            $context->logger->info('MOT history is off; nothing sent.');

            return JobResult::ok($this->translator->trans('mot_history.job.off'));
        }

        $due = $this->due();
        $counts = ['due' => count($due), 'refreshed' => 0, 'added' => 0, 'skipped' => 0, 'pinged' => 0];
        if ($due !== []) {
            try {
                $this->calls->run($provider, function (MotHistoryClient $client) use ($due, $context, &$counts): null {
                    foreach ($due as [$vehicle, $owner]) {
                        if ($context->cancelled()) {
                            break;
                        }
                        $this->refreshOne($client, $vehicle, $owner, $context, $counts);
                    }

                    return null;
                });
            } catch (MotHistoryFailure $failure) {
                $context->logger->error('The run stopped: {code}.', ['code' => $failure->error->value]);
                $message = $this->translator->trans($failure->error->messageKey(), $failure->parameters);

                return $failure->error === MotHistoryErrorCode::RateLimited
                    ? JobResult::partial($this->summary($counts) . ' ' . $message, $counts)
                    : JobResult::failed($message, $counts);
            }
        }

        // Keep-alive (#327, #342): after the fetches, so a run that fetched doesn't call again.
        $lastSuccess = $this->config->status()->lastSuccessAt;
        $limit = $this->clock->now()->sub(new DateInterval('P' . self::KEEP_ALIVE_DAYS . 'D'));
        if ($lastSuccess === null || $lastSuccess < $limit) {
            try {
                $this->calls->test($provider);
                $counts['pinged'] = 1;
                $context->logger->info('Kept the key in use: one call that sends no vehicle.');
            } catch (MotHistoryFailure $failure) {
                $context->logger->error('The keep-alive call failed: {code}.', ['code' => $failure->error->value]);

                return JobResult::failed($this->translator->trans($failure->error->messageKey(), $failure->parameters), $counts);
            }
        }

        return JobResult::ok($this->summary($counts), $counts);
    }

    /**
     * @param array<string, int> $counts
     * @throws MotHistoryFailure when the whole run must stop
     */
    private function refreshOne(
        MotHistoryClient $client,
        Vehicle $vehicle,
        User $owner,
        JobContext $context,
        array &$counts,
    ): void {
        try {
            $outcome = $this->fetcher->refresh($client, $vehicle);
        } catch (MotHistoryUnavailable $unavailable) {
            $context->logger->warning('Vehicle {id} skipped: {reason}.', [
                'id' => $vehicle->id,
                'reason' => $unavailable->getMessage(),
            ]);
            $counts['skipped']++;

            return;
        } catch (MotHistoryFailure $failure) {
            if (self::stopsTheRun($failure->error)) {
                throw $failure;
            }
            $context->logger->warning('Vehicle {id} failed: {code}.', ['id' => $vehicle->id, 'code' => $failure->error->value]);
            $counts['skipped']++;

            return;
        }
        if (!$outcome->found || $outcome->refusedAs !== null) {
            $context->logger->warning('Vehicle {id}: {reason}.', [
                'id' => $vehicle->id,
                'reason' => $outcome->found ? 'the make disagrees; nothing stored' : 'no DVSA record',
            ]);
            $counts['skipped']++;

            return;
        }
        $counts['refreshed']++;
        $counts['added'] += $outcome->added;
        if ($this->features->isEnabled(Feature::Issues)) {
            // "Advised again at the MOT on …" in the owner's language and units.
            $this->scope->run($owner, fn (): int => $this->review->applyRepeats($vehicle));
        }
        $context->logger->info('Vehicle {id}: {added} new test(s), {updated} brought up to date.', [
            'id' => $vehicle->id,
            'added' => $outcome->added,
            'updated' => $outcome->updated,
        ]);
    }

    /**
     * The vehicles in the window today, each with its owner.
     *
     * @return list<array{0: Vehicle, 1: User}>
     */
    private function due(): array
    {
        // By day, so a daily run a few seconds earlier than last week's still finds it.
        $before = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)
            ->sub(new DateInterval('P' . (self::REFETCH_DAYS - 1) . 'D'));
        $dates = $this->tests->refreshCandidates($before);
        $due = [];
        foreach ($this->vehicles->listByIds(array_keys($dates)) as $vehicle) {
            $owner = $this->users->find($vehicle->userId);
            if ($owner === null || !isset($dates[$vehicle->id])) {
                continue;
            }
            if (
                VehicleIdentifier::registration($vehicle->data->registration) === null
                && VehicleIdentifier::vin($vehicle->data->vin) === null
            ) {
                continue;
            }
            $today = LocalTime::today($this->clock, $owner->preferences->timeZone());
            if (self::inWindow($dates[$vehicle->id], $today)) {
                $due[] = [$vehicle, $owner];
            }
        }

        return $due;
    }

    /**
     * From 14 days before the MOT falls due until 60 days after.
     */
    public static function inWindow(DateTimeImmutable $dueOn, DateTimeImmutable $today): bool
    {
        $opens = $dueOn->sub(new DateInterval('P' . self::DAYS_BEFORE . 'D'))->format('Y-m-d');
        $closes = $dueOn->add(new DateInterval('P' . self::DAYS_AFTER . 'D'))->format('Y-m-d');
        $day = $today->format('Y-m-d');

        return $day >= $opens && $day <= $closes;
    }

    /**
     * DVSA busy, or Logbook's credentials or connection broken: no other
     * vehicle would fare better this run.
     */
    private static function stopsTheRun(MotHistoryErrorCode $error): bool
    {
        return match ($error) {
            MotHistoryErrorCode::RateLimited,
            MotHistoryErrorCode::Unauthorised,
            MotHistoryErrorCode::Credentials,
            MotHistoryErrorCode::TokenUrl,
            MotHistoryErrorCode::Timeout,
            MotHistoryErrorCode::Network => true,
            default => false,
        };
    }

    /**
     * @param array<string, int> $counts
     */
    private function summary(array $counts): string
    {
        return $this->translator->trans('mot_history.job.summary', $counts);
    }
}
