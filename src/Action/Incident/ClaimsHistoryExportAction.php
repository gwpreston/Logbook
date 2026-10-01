<?php

declare(strict_types=1);

namespace Logbook\Action\Incident;

use Logbook\Service\Export\CsvExporter;
use Logbook\Service\Incident\ClaimsFilter;
use Logbook\Service\Incident\ClaimsHistory;
use Logbook\Support\Http\CsvResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /incidents/history.csv — the claims history with the page's filters,
 * never the other party (spec.md §7.29).
 */
final readonly class ClaimsHistoryExportAction
{
    public function __construct(
        private ClaimsHistory $history,
        private CsvExporter $exporter,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $report = $this->history->report($user, ClaimsFilter::fromQuery($request->getQueryParams()));

        return CsvResponder::send($response, $this->exporter->claimsHistory($report));
    }
}
