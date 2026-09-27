<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\User\UserService;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Display\Theme;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Security\SafeRedirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;

/**
 * POST /settings/theme — the quick light/dark toggle in the app shell. Saves
 * the preference and returns to the page it was used on.
 */
final readonly class SetThemeAction
{
    public function __construct(
        private UserService $users,
        private Redirector $redirect,
        private AppSettings $settings,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = RequestContext::form($request);
        $theme = Theme::tryFrom(is_string($input['theme'] ?? null) ? $input['theme'] : '');
        if ($theme === null) {
            throw new HttpBadRequestException($request);
        }

        $this->users->setTheme(RequestContext::requireUser($request), $theme);

        $returnTo = $input['return_to'] ?? null;
        $back = SafeRedirect::localPath(is_string($returnTo) ? $returnTo : null, $this->settings->basePath);

        return $back !== null ? $this->redirect->to($back) : $this->redirect->toRoute('home');
    }
}
