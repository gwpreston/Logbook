<?php

declare(strict_types=1);

namespace Logbook\Action\Export;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\Export\CsvExporter;
use Logbook\Service\Export\ExportModule;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\CsvResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /vehicles/{id}/export/{module}.csv — one of the vehicle's lists as CSV
 * (spec.md §7.7), in the owner's units. Archived vehicles export too: it is
 * their data. A switched-off module does not export (404).
 */
final readonly class ExportModuleAction
{
    public function __construct(
        private VehicleService $vehicles,
        private CsvExporter $exporter,
        private FeatureToggles $features,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $module = ExportModule::tryFrom($args['module'] ?? '') ?? throw new HttpNotFoundException($request);
        $feature = $module->feature();
        if ($feature !== null && !$this->features->isEnabled($feature)) {
            throw new HttpNotFoundException($request);
        }

        return CsvResponder::send(
            $response,
            $this->exporter->module(RequestContext::requireUser($request), $vehicle, $module),
        );
    }
}
