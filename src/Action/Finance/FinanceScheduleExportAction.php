<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Service\Export\FinanceCsv;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Csv\CsvTable;
use Logbook\Support\Http\CsvResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/finance/{agreement}/schedule.csv — one agreement's
 * schedule (spec.md §7.32 *Agreement page*), never its number.
 */
final readonly class FinanceScheduleExportAction
{
    public function __construct(
        private FinanceService $finance,
        private FinanceCsv $csv,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $agreement = FinanceRoute::agreement($this->finance, $user, $vehicle, $request, $args);
        [$header, $rows] = $this->csv->scheduleTable($this->finance->view($user, $vehicle, $agreement));
        $name = sprintf(
            'logbook-%s-finance-%d-%s.csv',
            CsvTable::slug($vehicle->name(), 'vehicle-' . $vehicle->id),
            $agreement->id,
            $this->finance->ownerToday($user, $vehicle)->format('Y-m-d'),
        );

        return CsvResponder::send($response, new CsvTable($name, $header, $rows));
    }
}
