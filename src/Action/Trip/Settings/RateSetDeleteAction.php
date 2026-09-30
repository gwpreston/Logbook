<?php

declare(strict_types=1);

namespace Logbook\Action\Trip\Settings;

use Logbook\Service\Trip\MileageRateService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /settings/trips/rates/{set}/delete — confirm, then delete a rate
 * set. Trips it valued fall to the set before it, or have no value.
 */
final readonly class RateSetDeleteAction
{
    public function __construct(
        private MileageRateService $rates,
        private DisplayFormatter $formatter,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $set = $this->rates->find($user, (int) ($args['set'] ?? 0)) ?? throw new HttpNotFoundException($request);
        $params = ['date' => $this->formatter->date($set->data->effectiveFrom)];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'back_label' => 'trip.settings.title',
                'nav' => 'settings',
                'title' => 'trip.rates.delete_title',
                'body' => 'trip.rates.delete_body',
                'params' => $params,
                'action' => ['settings.trips.rates.delete', ['set' => $set->id]],
                'cancel' => ['settings.trips', []],
            ]);
        }

        $this->rates->delete($user, $set);
        RequestContext::session($request)->flash('success', 'trip.rates.deleted', $params);

        return $this->redirect->toRoute('settings.trips');
    }
}
