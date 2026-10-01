<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Repository\UserRepository;
use Logbook\Service\Auth\WelcomeForm;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\FormOptions;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /welcome — once, for a user created on first single sign-on
 * (spec.md §7.9): language, time zone, units and currency, as an
 * invitation asks. *Skip* keeps the defaults. Without a welcome pending,
 * it goes home.
 */
final readonly class WelcomeAction
{
    public function __construct(
        private UserRepository $users,
        private View $view,
        private Redirector $redirect,
        private AvailableLocales $locales,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $session = RequestContext::session($request);
        if (!$session->welcomePending()) {
            return $this->redirect->toRoute('home');
        }

        if ($request->getMethod() !== 'POST') {
            return $this->form($request, $response, [
                'locale' => $user->preferences->locale,
                'timezone' => $user->preferences->timezone,
                'currency' => $user->preferences->currency,
                'units' => UnitPreset::Metric->value,
            ]);
        }

        $input = RequestContext::form($request);
        if (($input['skip'] ?? null) === null) {
            $preferences = WelcomeForm::parse($input, RequestContext::locale($request), $this->locales, $user);
            if ($preferences instanceof ValidationErrors) {
                return $this->form($request, $response, RequestContext::formValues($request), $preferences, 422);
            }
            $this->users->updateProfile($user->id, $user->displayName, $preferences, $this->clock->now());
        }
        $next = $session->finishWelcome();
        $session->flash('success', 'invite.welcome', ['name' => $user->displayName]);

        return $next !== null ? $this->redirect->to($next) : $this->redirect->toRoute('home');
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
        return $this->view->render($request, $response, 'auth/welcome.twig', [
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'locale_options' => FormOptions::locales($this->locales),
            'timezone_options' => FormOptions::timezones(),
            'currency_options' => FormOptions::currencies(RequestContext::locale($request)),
            'unit_presets' => UnitPreset::cases(),
        ], $status);
    }
}
