<?php

declare(strict_types=1);

namespace Logbook\Action\Station;

use Logbook\Service\Station\DuplicatePair;
use Logbook\Service\Station\StationService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /stations/duplicates — pairs of stations that may be one forecourt
 * (spec.md §7.33 *Duplicates*), each linking to the merge form. Pairs the
 * user may not merge are listed without the link.
 */
final readonly class DuplicatesAction
{
    public function __construct(private StationService $stations, private View $view)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $pairs = array_map(
            fn (DuplicatePair $pair): array => [
                'pair' => $pair,
                // Merging keeps one station and merges the other away: allowed
                // when the user may edit the one merged away.
                'merge_second' => $this->stations->canEdit($user, $pair->second),
                'merge_first' => $this->stations->canEdit($user, $pair->first),
            ],
            $this->stations->duplicates(),
        );

        return $this->view->render($request, $response, 'stations/duplicates.twig', ['pairs' => $pairs]);
    }
}
