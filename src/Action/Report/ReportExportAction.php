<?php

declare(strict_types=1);

namespace Logbook\Action\Report;

use Logbook\Service\Export\CsvExporter;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\CsvResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /reports/export.csv — the ledger lines of the report with the same
 * filters, as CSV (spec.md §7.7).
 */
final readonly class ReportExportAction
{
    public function __construct(
        private ReportService $reports,
        private CsvExporter $exporter,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $report = $this->reports->build($user, ReportFilter::fromQuery($request->getQueryParams(), $today));

        return CsvResponder::send($response, $this->exporter->report($report));
    }
}
