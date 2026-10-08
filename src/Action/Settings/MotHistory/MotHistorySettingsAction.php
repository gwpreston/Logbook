<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\MotHistory;

use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\MotHistorySecrets;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /settings/mot-history — Settings → MOT history (spec.md §7.38),
 * admins only: the provider (off by default) with what it sends and its
 * licence, its credentials (sealed or `env:NAME`, never shown back), *Test*
 * and the last call. A 404 with `compliance` off or in demo mode.
 */
final readonly class MotHistorySettingsAction
{
    private const int MAX_SECRET = 2000;

    public function __construct(
        private MotHistoryConfig $config,
        private MotHistorySecrets $secrets,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->config->available()) {
            throw new HttpNotFoundException($request);
        }
        $errors = [];
        $values = ['provider' => $this->config->providerCode() ?? ''];

        if ($request->getMethod() === 'POST') {
            $input = RequestContext::form($request);
            $values = ['provider' => is_string($input['provider'] ?? null) ? $input['provider'] : ''];
            $typed = is_array($input['secret'] ?? null) ? $input['secret'] : [];
            $errors = $this->save($values['provider'], $typed);
            if ($errors === []) {
                RequestContext::session($request)->flash('success', 'mot_history.settings.saved');

                return $this->redirect->toRoute('settings.mot_history');
            }
        }

        $providers = [];
        foreach ($this->config->registry()->all() as $candidate) {
            $slots = array_keys($candidate->credentials());
            $providers[] = [
                'provider' => $candidate,
                'credentials' => $this->secrets->states($candidate),
                'variables' => array_combine($slots, array_map(
                    fn (string $slot): ?string => $this->secrets->variable($candidate, $slot),
                    $slots,
                )),
            ];
        }

        return $this->view->render($request, $response, 'settings/mot_history/index.twig', [
            'providers' => $providers,
            'enabled' => $this->config->provider(),
            'values' => $values,
            'errors' => array_map(static fn (string $key): array => ['key' => $key, 'params' => []], $errors),
            'status' => $this->config->status(),
        ]);
    }

    /**
     * @param array<mixed> $typed credentials typed, by slot
     * @return array<string, string> errors by field (translation keys)
     */
    private function save(string $code, array $typed): array
    {
        $errors = [];
        $provider = $code === '' ? null : $this->config->registry()->get($code);
        if ($code !== '' && $provider === null) {
            $errors['provider'] = 'mot_history.settings.error.provider';
        }

        // Credentials are kept per provider, so they can be typed before it is enabled.
        $target = $provider ?? $this->config->registry()->get($this->config->providerCode())
            ?? ($this->config->registry()->all()[0] ?? null);
        $store = [];
        if ($target === null) {
            return $errors;
        }
        foreach (array_keys($target->credentials()) as $slot) {
            $value = is_string($typed[$slot] ?? null) ? trim($typed[$slot]) : '';
            if ($value === '') {
                continue;
            }
            $error = match (true) {
                mb_strlen($value) > self::MAX_SECRET => 'mot_history.settings.error.secret_length',
                !$this->secrets->canStore($value) => 'mot_history.settings.error.secret_store',
                !str_starts_with($value, 'env:') && !$target->acceptsCredential($slot, $value)
                    => 'mot_history.settings.error.' . $slot,
                default => null,
            };
            if ($error !== null) {
                $errors['secret.' . $slot] = $error;
                continue;
            }
            $store[$slot] = $value;
        }
        if ($errors !== []) {
            return $errors;
        }

        foreach ($store as $slot => $value) {
            $this->secrets->store($target, $slot, $value);
        }
        if ($provider !== null && !$this->secrets->complete($provider)) {
            return ['provider' => 'mot_history.settings.error.credentials'];
        }
        $this->config->saveProvider($provider);

        return [];
    }
}
