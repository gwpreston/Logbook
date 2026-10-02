<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Places;

use Logbook\Service\Station\PlaceService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /settings/places/{place}/delete — forget one of the user's places.
 */
final readonly class PlaceDeleteAction
{
    public function __construct(private PlaceService $places, private Redirector $redirect)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $place = $this->places->find($user, (int) ($args['place'] ?? 0)) ?? throw new HttpNotFoundException($request);
        $this->places->delete($user, $place);
        RequestContext::session($request)->flash('success', 'places.deleted');

        return $this->redirect->toRoute('settings.places');
    }
}
