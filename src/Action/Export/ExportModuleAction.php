<?php

declare(strict_types=1);

namespace Logbook\Action\Export;

use Logbook\Service\Export\CsvExporter;
use Logbook\Service\Export\ExportModule;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\Finance\FinanceService;
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
        private CsvExporter $exporter,
        private FeatureToggles $features,
        private FinanceService $finance,
        private MotHistoryConfig $motHistory,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $module = ExportModule::tryFrom($args['module'] ?? '') ?? throw new HttpNotFoundException($request);
        $feature = $module->feature();
        if ($feature !== null && !$this->features->isEnabled($feature)) {
            throw new HttpNotFoundException($request);
        }
        // MOT tests only while MOT history is on (spec.md §7.38).
        if ($module === ExportModule::MotTests && !$this->motHistory->enabled()) {
            throw new HttpNotFoundException($request);
        }
        // Finance needs ViewCosts as well as Manage (spec.md §7.32 *Access*).
        if ($module === ExportModule::Finance && !$this->finance->canSee(RequestContext::requireUser($request), $vehicle)) {
            throw new HttpNotFoundException($request);
        }

        return CsvResponder::send(
            $response,
            $this->exporter->module(RequestContext::requireUser($request), $vehicle, $module),
        );
    }
}
