<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Tyre\TyreSettingsForm;
use Logbook\Service\Tyre\TyreSettingsStore;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /settings/tyres — replace-at, legal-minimum and age-limit
 * thresholds (spec.md §7.17), typed in the owner's depth unit. Routed only
 * while the tyres module is on.
 */
final readonly class TyreSettingsAction
{
    public function __construct(
        private View $view,
        private TyreSettingsStore $settings,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $current = $this->settings->thresholds($user->id);

        if ($request->getMethod() === 'POST') {
            $parsed = TyreSettingsForm::parse(RequestContext::form($request), $user->preferences, $current);
            if (!$parsed instanceof ValidationErrors) {
                $this->settings->saveThresholds($user->id, $parsed);
                RequestContext::session($request)->flash('success', 'tyre.settings.saved');

                return $this->redirect->toRoute('settings.tyres');
            }

            return $this->view->render($request, $response, 'settings/tyres.twig', [
                'values' => RequestContext::formValues($request),
                'errors' => $parsed->all(),
            ], 422);
        }

        return $this->view->render($request, $response, 'settings/tyres.twig', [
            'values' => TyreSettingsForm::values($current, $user->preferences),
            'errors' => [],
        ]);
    }
}
