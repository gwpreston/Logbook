<?php

declare(strict_types=1);

namespace Logbook\Action\Dashboard;

use Logbook\Service\Dashboard\DashboardLayoutStore;
use Logbook\Service\Dashboard\DashboardWidget;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /dashboard/layout — change the owner's dashboard layout (spec.md §7.8).
 *
 * One form field says what to do:
 *  - `move=up|down` with `widget` (the customise buttons),
 *  - `toggle` with `widget` (hide / show),
 *  - `reset` (back to the default),
 *  - `order` — comma-separated widget ids (drag and drop).
 * A script (`X-Requested-With: fetch`) gets 204; a plain form post is sent
 * back to the customise view.
 */
final readonly class SaveDashboardLayoutAction
{
    public function __construct(
        private DashboardLayoutStore $layouts,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $form = RequestContext::form($request);
        $layout = $this->layouts->load($user->id);
        $widget = is_string($form['widget'] ?? null) ? DashboardWidget::tryFrom($form['widget']) : null;

        if (isset($form['reset'])) {
            $this->layouts->reset($user->id);
        } elseif (is_string($form['order'] ?? null)) {
            $this->layouts->save($user->id, $layout->reorder(explode(',', $form['order'])));
        } elseif ($widget !== null && in_array($form['move'] ?? null, ['up', 'down'], true)) {
            $this->layouts->save($user->id, $layout->move($widget, $form['move'] === 'up' ? -1 : 1));
        } elseif ($widget !== null && isset($form['toggle'])) {
            $this->layouts->save($user->id, $layout->toggle($widget));
        }

        if ($request->getHeaderLine('X-Requested-With') === 'fetch') {
            return $response->withStatus(204);
        }

        // Back to the widget that was moved, so keyboard users keep their place.
        $anchor = $widget !== null ? '#widget-' . $widget->value : '';

        return $this->redirect->to($this->redirect->urlFor('home', [], ['customise' => '1']) . $anchor);
    }
}
