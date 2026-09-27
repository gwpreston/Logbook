<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\User\ProfileForm;
use Logbook\Service\User\UserService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/preferences — display name, appearance, units, currency,
 * language and time zone. Takes effect from the redirect onwards.
 */
final readonly class SavePreferencesAction
{
    public function __construct(
        private UserService $users,
        private SettingsPage $page,
        private Redirector $redirect,
        private AvailableLocales $locales,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $profile = ProfileForm::parse(RequestContext::form($request), RequestContext::locale($request), $this->locales);
        if ($profile instanceof ValidationErrors) {
            return $this->page->render($request, $response, RequestContext::formValues($request), $profile, null, 422);
        }

        $this->users->updateProfile(RequestContext::requireUser($request), $profile);
        RequestContext::session($request)->flash('success', 'settings.saved');

        return $this->redirect->toRoute('settings');
    }
}
