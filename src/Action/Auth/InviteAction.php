<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Domain\User\Invitation;
use Logbook\Domain\User\InvitationKind;
use Logbook\Service\Auth\SetupForm;
use Logbook\Service\User\InvitationService;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;
use Logbook\Support\View\FormOptions;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /invite/{token} — a one-time link from an admin (spec.md §7.9).
 * An invite asks for what setup asks (the username is the invite's) and
 * creates the account; a reset asks for a new password. Either signs the
 * user in. A used, expired, revoked or unknown link is a 404.
 */
final readonly class InviteAction
{
    public function __construct(
        private InvitationService $invitations,
        private View $view,
        private Redirector $redirect,
        private AvailableLocales $locales,
        private AppSettings $settings,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $args['token'] ?? '';
        $invitation = $this->invitations->open($token);
        if ($invitation === null || $invitation->kind === InvitationKind::Login) {
            throw new HttpNotFoundException($request);
        }

        $locale = RequestContext::locale($request);
        if ($request->getMethod() !== 'POST') {
            return $this->form($request, $response, $invitation, $token, [
                'display_name' => $invitation->displayName,
                'locale' => $locale,
                'timezone' => $this->settings->timezone,
                'currency' => $this->settings->currency,
                'units' => UnitPreset::Metric->value,
            ]);
        }

        $input = RequestContext::form($request);
        if ($invitation->kind === InvitationKind::Reset) {
            $validator = new Validator($input, $locale);
            $password = $validator->password('password');
            if ($password !== null && ($input['password_confirm'] ?? null) !== $password) {
                $validator->addError('password_confirm', 'auth.password_mismatch');
            }
            if ($password === null || !$validator->errors()->isEmpty()) {
                return $this->form($request, $response, $invitation, $token, [], $validator->errors(), 422);
            }
            $user = $this->invitations->acceptReset($invitation, $password);
            $flash = 'invite.password_set';
        } else {
            $data = SetupForm::parse(['username' => $invitation->username] + $input, $locale, $this->locales);
            if ($data instanceof ValidationErrors) {
                return $this->form($request, $response, $invitation, $token, RequestContext::formValues($request), $data, 422);
            }
            $user = $this->invitations->acceptInvite($invitation, $data);
            $flash = 'invite.welcome';
        }
        if ($user === null) {
            throw new HttpNotFoundException($request);
        }

        $session = RequestContext::session($request);
        $session->signIn($user->id);
        $session->flash('success', $flash, ['name' => $user->displayName]);

        return $this->redirect->toRoute('home');
    }

    /**
     * @param array<string, string> $values
     */
    private function form(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Invitation $invitation,
        string $token,
        array $values,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $locale = RequestContext::locale($request);

        return $this->view->render($request, $response, 'auth/invite.twig', [
            'invitation' => $invitation,
            'token' => $token,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'locale_options' => FormOptions::locales($this->locales),
            'timezone_options' => FormOptions::timezones(),
            'currency_options' => FormOptions::currencies($locale),
            'unit_presets' => UnitPreset::cases(),
        ], $status)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }
}
