<?php

declare(strict_types=1);

namespace Logbook\Action\Trip;

use Logbook\Service\Export\CsvExporter;
use Logbook\Service\Trip\ClaimFilter;
use Logbook\Service\Trip\ClaimReportService;
use Logbook\Service\Trip\TripSettingsStore;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\CsvResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /trips/claim.csv — the claim report's rows (spec.md §7.23), with the
 * same filters: `mileage-claim-<tax year>.csv`.
 */
final readonly class ClaimExportAction
{
    public function __construct(
        private ClaimReportService $claims,
        private TripSettingsStore $settings,
        private CsvExporter $exporter,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $filter = ClaimFilter::fromQuery($request->getQueryParams(), $this->settings->for($user)->taxYearStart, $today);

        return CsvResponder::send($response, $this->exporter->claim($this->claims->build($user, $filter)));
    }
}
