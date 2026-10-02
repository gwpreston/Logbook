<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Station\StationListing;
use Logbook\Service\Station\StationService;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/stations?q=&favourites=true — stations, favourites first,
 * then by the key user's last visit, with what they paid at each (spec.md
 * §7.20, §7.33). Never their places.
 */
final readonly class ListStationsAction
{
    public function __construct(
        private StationService $stations,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $params = $request->getQueryParams();
        $query = is_string($params['q'] ?? null) ? mb_substr(trim($params['q']), 0, 100) : '';
        $favourites = in_array($params['favourites'] ?? '', ['true', '1'], true);

        $rows = $this->stations->listing($user, $query);
        if ($favourites) {
            $rows = array_values(array_filter($rows, static fn (StationListing $row): bool => $row->favourite));
        }

        return $this->responder->json([
            'items' => array_map(
                static fn (StationListing $row): array => Serializer::station($row->station, $row->summary, $row->favourite),
                $rows,
            ),
        ]);
    }
}
