<?php

declare(strict_types=1);

namespace Logbook\Action\Forecast;

use Logbook\Service\Export\CsvExporter;
use Logbook\Service\Forecast\ComingUp;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\CsvResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /upcoming.csv — *Coming up* with the page's `?vehicle=`: one row per
 * item, then one per vehicle per month for fuel (spec.md §7.7).
 */
final readonly class ComingUpExportAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ComingUp $comingUp,
        private CsvExporter $exporter,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $scope = ComingUpScope::of($this->vehicles->listFleet($user), $request->getQueryParams());

        return CsvResponder::send($response, $this->exporter->comingUp($this->comingUp->forecast($user, $scope->vehicles)));
    }
}
