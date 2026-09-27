<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Service\Auth\AuthService;
use Logbook\Service\Auth\SetupAlreadyCompleted;
use Logbook\Service\Auth\SetupForm;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\FormOptions;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /setup — first-run account creation. Only reachable while no
 * account exists; afterwards it redirects to sign-in.
 */
final readonly class SetupAction
{
    public function __construct(
        private AuthService $auth,
        private View $view,
        private Redirector $redirect,
        private AvailableLocales $locales,
        private AppSettings $settings,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->auth->setupRequired()) {
            return $this->redirect->toRoute(RequestContext::user($request) === null ? 'login' : 'home');
        }

        $locale = RequestContext::locale($request);
        if ($request->getMethod() !== 'POST') {
            return $this->form($request, $response, [
                'locale' => $locale,
                'timezone' => $this->settings->timezone,
                'currency' => $this->settings->currency,
                'units' => UnitPreset::Metric->value,
            ]);
        }

        $input = RequestContext::form($request);
        $data = SetupForm::parse($input, $locale, $this->locales);
        if ($data instanceof ValidationErrors) {
            return $this->form($request, $response, RequestContext::formValues($request), $data, 422);
        }

        try {
            $user = $this->auth->createInitialUser($data);
        } catch (SetupAlreadyCompleted) {
            return $this->redirect->toRoute('login');
        }

        $session = RequestContext::session($request);
        $session->signIn($user->id);
        $session->flash('success', 'setup.done', ['name' => $user->displayName]);

        return $this->redirect->toRoute('home');
    }

    /**
     * @param array<string, string> $values
     */
    private function form(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $values,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $locale = RequestContext::locale($request);

        return $this->view->render($request, $response, 'auth/setup.twig', [
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'locale_options' => FormOptions::locales($this->locales),
            'timezone_options' => FormOptions::timezones(),
            'currency_options' => FormOptions::currencies($locale),
            'unit_presets' => UnitPreset::cases(),
        ], $status);
    }
}
