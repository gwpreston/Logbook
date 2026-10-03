<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\FuelPrices\ListedPrices;
use Logbook\Service\Station\StationService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\FuelPriceSerializer;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/stations/{station} — one station and what the key user paid there
 * (spec.md §7.20, §7.33). A merged station's id answers with the station
 * it became.
 */
final readonly class ShowStationAction
{
    public function __construct(
        private StationService $stations,
        private ListedPrices $listed,
        private FuelPriceSerializer $prices,
        private ApiResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $station = $this->stations->resolve((int) ($args['station'] ?? 0))
            ?? throw ApiProblem::notFound('There is no such station.');
        $summary = $this->stations->summaries($user, [$station->id])[$station->id] ?? null;

        return $this->responder->json(Serializer::station(
            $station,
            $summary,
            $this->stations->isFavourite($user, $station),
            $this->prices->listed($this->listed->forStation($station)),
        ));
    }
}
