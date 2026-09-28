<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Domain\Feature\Feature;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /settings/modules — switch modules on and off (spec.md §7.10).
 * A plain form of checkboxes: a module left unticked is switched off.
 */
final readonly class ModuleSettingsAction
{
    public function __construct(
        private FeatureToggles $features,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'settings/modules.twig', [
                'modules' => Feature::cases(),
                'enabled' => $this->features->all(),
            ]);
        }

        $input = RequestContext::form($request);
        $enabled = array_values(array_filter(
            Feature::cases(),
            static fn (Feature $feature): bool => ($input[$feature->value] ?? '') === '1',
        ));
        $this->features->save($enabled);
        RequestContext::session($request)->flash('success', 'modules.saved');

        return $this->redirect->toRoute('settings.modules');
    }
}
