<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\MotHistory;

use Logbook\Service\MotHistory\MotHistoryCalls;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\MotHistoryFailure;
use Logbook\Service\MotHistory\MotHistorySecrets;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /settings/mot-history/test — *Test* (spec.md §7.38, #327): signs in
 * with the saved credentials and makes the call that sends no vehicle, so
 * the client credentials and the API key are both checked. The outcome is
 * the page's last call.
 */
final readonly class TestMotHistoryAction
{
    public function __construct(
        private MotHistoryConfig $config,
        private MotHistorySecrets $secrets,
        private MotHistoryCalls $calls,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->config->available()) {
            throw new HttpNotFoundException($request);
        }
        $session = RequestContext::session($request);
        $form = RequestContext::form($request);
        $code = is_string($form['provider'] ?? null) ? $form['provider'] : '';
        $provider = $this->config->registry()->get($code === '' ? $this->config->providerCode() : $code);
        if ($provider === null || !$this->secrets->complete($provider)) {
            $session->flash('error', 'mot_history.settings.test.incomplete');

            return $this->redirect->toRoute('settings.mot_history');
        }
        try {
            $this->calls->test($provider);
            $session->flash('success', 'mot_history.settings.test.ok');
        } catch (MotHistoryFailure) {
            // The page shows the redacted reason as the last call.
            $session->flash('error', 'mot_history.settings.test.failed');
        }

        return $this->redirect->toRoute('settings.mot_history');
    }
}
