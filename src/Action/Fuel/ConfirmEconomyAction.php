<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Service\Fuel\EconomyNotCheckable;
use Logbook\Service\Fuel\FuelService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /vehicles/{id}/fuel/{entry}/economy — *Looks right* on an economy
 * check (spec.md §7.3): the segment's current figure is confirmed until it
 * changes; with `undo=1` the confirmation is cleared. A plain form, so it
 * works without JS; returns to the page it was sent from.
 */
final readonly class ConfirmEconomyAction
{
    public function __construct(
        private FuelService $fuel,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $entry = FuelRoute::entry($this->fuel, $vehicle, $request, $args);
        $undo = (RequestContext::form($request)['undo'] ?? '') === '1';

        if ($undo) {
            $this->fuel->unconfirmEconomy($vehicle, $entry);
        } else {
            try {
                $this->fuel->confirmEconomy($vehicle, $entry);
            } catch (EconomyNotCheckable) {
                throw new HttpNotFoundException($request);
            }
        }
        RequestContext::session($request)->flash('success', $undo ? 'fuel.check.undone' : 'fuel.check.confirmed');

        return $this->redirect->backOr($request, 'fuel.index', ['id' => (string) $vehicle->id]);
    }
}
