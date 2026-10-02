<?php

declare(strict_types=1);

namespace Logbook\Action\Station;

use Logbook\Domain\Station\StationName;
use Logbook\Service\Station\StationHint;
use Logbook\Service\Station\StationListing;
use Logbook\Service\Station\StationService;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /stations/search?q= — what the fill-up form's combo box offers, as
 * JSON (spec.md §7.33 *Fill-up form*): favourites first, then recent, then
 * the rest, each with its "Last time here" hint; and whether the typed
 * name matches a station already (so *Add "…"* is offered only when not).
 */
final readonly class SearchStationsAction
{
    public function __construct(private StationService $stations, private StationHint $hint)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $query = $request->getQueryParams()['q'] ?? '';
        $query = is_string($query) ? mb_substr(StationName::tidy($query), 0, 100) : '';

        $choices = $this->stations->choices($user, $query);
        $hints = $this->hint->forStations($user, array_map(static fn (StationListing $row): int => $row->station->id, $choices));
        $results = array_map(
            static fn (StationListing $row): array => [
                'id' => $row->station->id,
                'name' => $row->station->data->name,
                'brand' => $row->station->data->brand,
                'postcode' => $row->station->data->postcode,
                'favourite' => $row->favourite,
                'recent' => $row->summary !== null,
                'hint' => $hints[$row->station->id] ?? null,
            ],
            $choices,
        );
        $exact = $query === '' ? null : $this->stations->existing($query);

        $response->getBody()->write(json_encode([
            'query' => $query,
            'exact' => $exact?->id,
            'results' => $results,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
