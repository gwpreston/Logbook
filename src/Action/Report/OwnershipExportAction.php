<?php

declare(strict_types=1);

namespace Logbook\Action\Report;

use Logbook\Service\Export\CsvExporter;
use Logbook\Service\Report\OwnershipService;
use Logbook\Service\Report\ReportFilter;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\CsvResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /reports/ownership.csv — the ownership report with the same filters,
 * one row per vehicle (spec.md §7.7).
 */
final readonly class OwnershipExportAction
{
    public function __construct(
        private OwnershipService $ownership,
        private CsvExporter $exporter,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $report = $this->ownership->report($user, ReportFilter::fromQuery($request->getQueryParams(), $today), $today);

        return CsvResponder::send($response, $this->exporter->ownership($user, $report, $today));
    }
}
