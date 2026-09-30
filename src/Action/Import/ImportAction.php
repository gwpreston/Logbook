<?php

declare(strict_types=1);

namespace Logbook\Action\Import;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Export\ExportModule;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Import\CsvImporter;
use Logbook\Service\Import\DateOrder;
use Logbook\Service\Import\ImportField;
use Logbook\Service\Import\ImportMapper;
use Logbook\Service\Import\ImportOptions;
use Logbook\Service\Import\ImportPreview;
use Logbook\Service\Import\ImportRowStatus;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Csv\CsvReader;
use Logbook\Support\Csv\CsvTooLong;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Storage\StagedFiles;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\View\FormOptions;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/import/{module}/{token} — CSV import, steps 2 and 3
 * (spec.md §7.13). GET: map the file's columns and, once the mapping form
 * is submitted (a GET form, so nothing changes), preview every row. POST:
 * import the importable rows and show what was imported and what was not.
 */
final readonly class ImportAction
{
    /** Rows listed individually in a preview (every problem row is always listed). */
    private const int PREVIEW_ROWS = 50;

    public function __construct(
        private VehicleService $vehicles,
        private FeatureToggles $features,
        private CsvImporter $importer,
        private StagedFiles $staged,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$vehicle, $module] = ImportRoute::resolve($this->features, $request, $args);
        $user = RequestContext::requireUser($request);
        $session = RequestContext::session($request);

        $token = $args['token'] ?? '';
        $import = $session->get(ImportRoute::SESSION);
        $path = is_array($import)
            && ($import['token'] ?? null) === $token
            && ($import['vehicle'] ?? null) === $vehicle->id
            && ($import['module'] ?? null) === $module->value
            ? $this->staged->path('import', $token)
            : null;
        $contents = $path === null ? false : file_get_contents($path);
        try {
            $csv = $contents === false ? null : CsvReader::read($contents, CsvImporter::MAX_ROWS);
        } catch (CsvTooLong) {
            $csv = null;
        }
        if ($csv === null || !is_array($import)) {
            $session->flash('warning', 'import.expired');

            return $this->redirect->toRoute('import.upload', ['id' => (string) $vehicle->id, 'module' => $module->value]);
        }

        $fields = ImportField::forModule($module);
        $guess = ImportMapper::guess($fields, $csv, $user->preferences, $this->importer->vocabulary($user));
        $posted = $request->getMethod() === 'POST';
        $input = $posted ? RequestContext::form($request) : $request->getQueryParams();
        $options = ImportMapper::fromQuery($fields, $csv, $input, $guess);
        $filename = is_string($import['name'] ?? null) ? $import['name'] : '';

        if (!$posted) {
            $preview = array_key_exists('date_order', $input)
                ? $this->importer->analyse($user, $vehicle, $module, $csv, $options)
                : null;

            return $this->render($request, $response, $vehicle, $module, $csv, $options, $filename, $token, $preview);
        }

        $preview = $this->importer->analyse($user, $vehicle, $module, $csv, $options);
        $error = match (true) {
            $preview->count(ImportRowStatus::Import) === 0 => 'import.error.nothing',
            $preview->count(ImportRowStatus::Invalid) > 0 && ($input['skip_invalid'] ?? '') !== '1'
                => 'import.error.skip_required',
            default => null,
        };
        if ($error !== null) {
            return $this->render(
                $request,
                $response,
                $vehicle,
                $module,
                $csv,
                $options,
                $filename,
                $token,
                $preview,
                $error,
                422,
            );
        }

        $result = $this->importer->import($user, $vehicle, $module, $csv, $options);
        $this->staged->discard('import', $token);
        $session->remove(ImportRoute::SESSION);

        return $this->view->render($request, $response, 'import/result.twig', [
            'vehicle' => $vehicle,
            'module' => $module,
            'filename' => $filename,
            'result' => $result,
            'statuses' => ImportRowStatus::cases(),
            'field_labels' => self::labels($fields),
        ]);
    }

    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        ExportModule $module,
        CsvReader $csv,
        ImportOptions $options,
        string $filename,
        string $token,
        ?ImportPreview $preview,
        ?string $error = null,
        int $status = 200,
    ): ResponseInterface {
        $fields = ImportField::forModule($module);
        $first = $csv->rows[0]['cells'] ?? [];
        $columns = [];
        foreach ($csv->header as $index => $name) {
            $columns[] = ['index' => $index, 'name' => $name, 'sample' => mb_substr(trim($first[$index] ?? ''), 0, 40)];
        }

        // Every problem row, and the first importable ones.
        $listed = [];
        $importable = 0;
        foreach ($preview === null ? [] : $preview->rows as $row) {
            if ($row->status !== ImportRowStatus::Import || ++$importable <= self::PREVIEW_ROWS) {
                $listed[] = $row;
            }
        }

        return $this->view->render($request, $response, 'import/map.twig', [
            'vehicle' => $vehicle,
            'module' => $module,
            'filename' => $filename,
            'fields' => $fields,
            'field_labels' => self::labels($fields),
            'columns' => $columns,
            'row_count' => count($csv->rows),
            'options' => $options,
            'query' => $options->toQuery(),
            'date_orders' => DateOrder::cases(),
            'distance_units' => DistanceUnit::cases(),
            'volume_units' => VolumeUnit::cases(),
            'timezone_options' => FormOptions::timezones(),
            'uses_distance' => in_array($module, [ExportModule::Fuel, ExportModule::Odometer, ExportModule::Maintenance], true),
            'uses_time' => in_array($module, [ExportModule::Fuel, ExportModule::Odometer], true),
            'currency' => $this->vehicles->currencyFor(RequestContext::requireUser($request), $vehicle),
            'preview' => $preview,
            'listed' => $listed,
            'importable_count' => $importable,
            'invalid_count' => $preview?->count(ImportRowStatus::Invalid) ?? 0,
            'unlisted_count' => max(0, $importable - self::PREVIEW_ROWS),
            'statuses' => ImportRowStatus::cases(),
            'error' => $error,
            'token' => $token,
        ], $status);
    }

    /**
     * @param list<ImportField> $fields
     * @return array<string, string> field key → translation key of its name
     */
    private static function labels(array $fields): array
    {
        $labels = [];
        foreach ($fields as $field) {
            $labels[$field->key] = $field->labelKey();
        }

        return $labels;
    }
}
