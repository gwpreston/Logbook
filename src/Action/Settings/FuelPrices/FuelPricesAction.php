<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\FuelPrices;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Repository\JobRunRepository;
use Logbook\Repository\ProviderStationRepository;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\FuelPriceSecrets;
use Logbook\Service\FuelPrices\FuelPriceSettings;
use Logbook\Service\FuelPrices\FuelPricesJob;
use Logbook\Service\FuelPrices\PriceProvider;
use Logbook\Service\Jobs\JobRegistry;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /settings/fuel-prices — Settings → Fuel prices (spec.md §7.34),
 * admins only: the provider (off by default) with what it sends and its
 * licence, its credentials (sealed or `env:NAME`, never shown back), the
 * refresh interval, the E5 mapping, and the last sync. *Sync now* posts to
 * the Jobs page's *Run now* for `fuel_prices`. A 404 with `stations` off.
 */
final readonly class FuelPricesAction
{
    public function __construct(
        private FuelPriceConfig $config,
        private FuelPriceSecrets $secrets,
        private ProviderStationRepository $providerStations,
        private JobRegistry $jobs,
        private JobRunner $runner,
        private JobRunRepository $runs,
        private ClockInterface $clock,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->config->available()) {
            throw new HttpNotFoundException($request);
        }
        $settings = $this->config->settings();
        $errors = [];
        $values = [
            'provider' => $settings->provider ?? '',
            'refresh' => (string) $settings->refresh,
            'e5' => $settings->e5->value,
        ];

        if ($request->getMethod() === 'POST') {
            $input = RequestContext::form($request);
            $values = [
                'provider' => is_string($input['provider'] ?? null) ? $input['provider'] : '',
                'refresh' => is_string($input['refresh'] ?? null) ? $input['refresh'] : '',
                'e5' => is_string($input['e5'] ?? null) ? $input['e5'] : '',
            ];
            $typed = is_array($input['secret'] ?? null) ? $input['secret'] : [];
            $errors = $this->save($values, $typed);
            if ($errors === []) {
                RequestContext::session($request)->flash('success', 'fuel_prices.settings.saved');

                return $this->redirect->toRoute('settings.fuel_prices');
            }
        }

        $provider = $this->config->provider();
        $job = $this->jobs->get(FuelPricesJob::NAME);
        $providers = [];
        foreach ($this->config->registry()->all() as $candidate) {
            $providers[] = [
                'provider' => $candidate,
                'credentials' => $this->secrets->states($candidate),
                'variables' => array_map(
                    fn (string $slot): ?string => $this->secrets->variable($candidate, $slot),
                    array_combine(array_keys($candidate->credentials()), array_keys($candidate->credentials())),
                ),
            ];
        }

        return $this->view->render($request, $response, 'settings/fuel_prices/index.twig', [
            'providers' => $providers,
            'enabled' => $provider,
            'values' => $values,
            'errors' => array_map(static fn (string $key): array => ['key' => $key, 'params' => []], $errors),
            'refresh_choices' => FuelPriceSettings::REFRESH_CHOICES,
            'e5_choices' => [FuelGrade::E5_97, FuelGrade::E5_98, FuelGrade::E5_99],
            'stations' => $provider === null ? 0 : $this->providerStations->count($provider->code()),
            'prices' => $provider === null ? 0 : $this->providerStations->countPrices($provider->code()),
            'last_synced' => $provider === null ? null : $this->providerStations->lastSynced($provider->code()),
            'last_run' => $this->runs->latestWorked(FuelPricesJob::NAME),
            'next' => $provider !== null && $job !== null ? $this->runner->nextRun($job, $this->clock->now()) : null,
            'newest_id' => $this->runs->newestId(),
        ]);
    }

    /**
     * @param array{provider: string, refresh: string, e5: string} $values
     * @param array<mixed> $typed credentials typed, by slot
     * @return array<string, string> errors by field (translation keys)
     */
    private function save(array $values, array $typed): array
    {
        $errors = [];
        $provider = $values['provider'] === '' ? null : $this->config->registry()->get($values['provider']);
        if ($values['provider'] !== '' && $provider === null) {
            $errors['provider'] = 'fuel_prices.settings.error.provider';
        }
        $refresh = ctype_digit($values['refresh']) ? (int) $values['refresh'] : 0;
        if (!in_array($refresh, FuelPriceSettings::REFRESH_CHOICES, true)) {
            $errors['refresh'] = 'fuel_prices.settings.error.refresh';
        }
        $e5 = FuelGrade::tryFrom($values['e5']);
        if (!in_array($e5, [FuelGrade::E5_97, FuelGrade::E5_98, FuelGrade::E5_99], true)) {
            $errors['e5'] = 'fuel_prices.settings.error.e5';
        }

        // Credentials are kept per provider, so they can be typed before it is enabled.
        $target = $provider ?? $this->config->registry()->get($this->config->settings()->provider);
        $store = [];
        foreach ($target === null ? [] : array_keys($target->credentials()) as $slot) {
            $value = is_string($typed[$slot] ?? null) ? trim($typed[$slot]) : '';
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > 1000 || !$this->secrets->canStore($value)) {
                $errors['secret.' . $slot] = mb_strlen($value) > 1000
                    ? 'fuel_prices.settings.error.secret_length'
                    : 'fuel_prices.settings.error.secret_store';
                continue;
            }
            $store[$slot] = $value;
        }
        if ($errors !== [] || $e5 === null) {
            return $errors;
        }

        if ($target instanceof PriceProvider) {
            foreach ($store as $slot => $value) {
                $this->secrets->store($target, $slot, $value);
            }
        }
        if ($provider !== null && !$this->secrets->complete($provider)) {
            return ['provider' => 'fuel_prices.settings.error.credentials'];
        }
        $this->config->save(new FuelPriceSettings($provider?->code(), $refresh, $e5));

        return [];
    }
}
