<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Service\Ai\SecretUnreadable;
use Logbook\Service\Jobs\Job;
use Logbook\Service\Jobs\JobContext;
use Logbook\Service\Jobs\JobResult;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `fuel_prices` (spec.md §7.30, §7.34): due every 30, 60 or 120 minutes
 * while a provider is enabled, never otherwise; a run by hand while off
 * fetches nothing. A feed that can't be read fails the run with the reason
 * (and, twice running, alerts the admins as any job); the current prices
 * stay.
 */
final readonly class FuelPricesJob implements Job
{
    public const string NAME = 'fuel_prices';

    public function __construct(
        private FuelPriceConfig $config,
        private FuelPriceSync $sync,
        private TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function interval(): ?int
    {
        $provider = $this->config->provider();

        return $provider instanceof BulkPriceProvider
            ? $this->config->settings()->refreshFor($provider) * 60
            : null;
    }

    public function run(JobContext $context): JobResult
    {
        $provider = $this->config->provider();
        if ($provider === null) {
            $context->logger->info('Fuel prices are off; nothing fetched.');

            return JobResult::ok($this->translator->trans('fuel_prices.summary.off'));
        }
        if (!$provider instanceof BulkPriceProvider) {
            return JobResult::ok($this->translator->trans('fuel_prices.summary.area'));
        }

        try {
            $outcome = $this->sync->run($provider, $context->logger, $context->cancelled(...));
        } catch (SecretUnreadable) {
            return JobResult::failed($this->translator->trans('fuel_prices.error.credentials'));
        } catch (FeedFailure $e) {
            $context->logger->error('The feed could not be read: {code}.', ['code' => $e->error->value] + $e->parameters);

            return JobResult::failed($this->translator->trans($e->error->messageKey(), $e->parameters));
        }

        $counts = $outcome->counts();
        $context->logger->info(
            '{stations} station(s) listed, {added} new, {removed} removed; {prices} price(s), {changed} changed; '
                . '{implausible} implausible, {corrected} in pounds, {skipped} skipped; {history} history row(s); '
                . '{linked} linked station(s) updated; {alerts} alert(s) sent; {requests} request(s).',
            $counts,
        );
        $summary = $this->translator->trans($outcome->full ? 'fuel_prices.summary.full' : 'fuel_prices.summary.incremental', [
            'stations' => $counts['stations'],
            'prices' => $counts['prices'],
            'removed' => $counts['removed'],
        ]);
        if ($counts['implausible'] + $counts['skipped'] > 0) {
            $summary .= ' ' . $this->translator->trans('fuel_prices.summary.skipped', [
                'implausible' => $counts['implausible'],
                'skipped' => $counts['skipped'],
            ]);
        }

        return JobResult::ok($summary, $counts);
    }
}
