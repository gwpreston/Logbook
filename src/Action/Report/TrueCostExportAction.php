<?php

declare(strict_types=1);

namespace Logbook\Action\Report;

use Logbook\Service\Export\CsvExporter;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\TrueCostService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\CsvResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /reports/true-cost.csv — the true cost trend with the same filters,
 * one row per vehicle and year (spec.md §7.35).
 */
final readonly class TrueCostExportAction
{
    public function __construct(
        private TrueCostService $trueCosts,
        private CsvExporter $exporter,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $rows = $this->trueCosts->fleet($user, ReportFilter::fromQuery($request->getQueryParams(), $today), $today);

        return CsvResponder::send($response, $this->exporter->trueCost($user, $rows, $today));
    }
}
