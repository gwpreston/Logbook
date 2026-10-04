<?php

declare(strict_types=1);

namespace Logbook\Action\ImportApp;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Import\App\AppImportOptions;
use Logbook\Service\Import\App\AppRow;
use Logbook\Service\Import\App\AppRowStatus;
use Logbook\Service\Import\App\AppVehiclePreview;
use Logbook\Service\Import\App\Fuelio\FuelioExport;
use Logbook\Service\Import\App\Fuelio\FuelioImporter;
use Logbook\Service\Import\App\Fuelio\FuelioReader;
use Logbook\Service\Import\App\SectionSplitter;
use Logbook\Service\Import\DateOrder;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Csv\CsvTooLong;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Storage\StagedFiles;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\ElectricEfficiencyUnit;
use Logbook\Support\Units\GasEfficiencyUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET|POST /settings/import-app/{token} — importing from another app,
 * steps 2 to 4 (spec.md §7.13): map (a GET form, bookmarkable, working
 * without JS), preview every row once the form is submitted, and import
 * (POST) in one transaction. Only active vehicles the user can manage are
 * offered; any other vehicle in the form is a 404.
 */
final readonly class ImportAppAction
{
    /** Importable rows listed per section (every other row is always listed). */
    private const int PREVIEW_ROWS = 50;

    public function __construct(
        private FuelioImporter $importer,
        private VehicleService $vehicles,
        private StagedFiles $staged,
        private DisplayFormatter $format,
        private TranslatorInterface $translator,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $session = RequestContext::session($request);
        $token = $args['token'] ?? '';
        $import = $session->get(ImportAppRoute::SESSION);
        $path = is_array($import) && ($import['token'] ?? null) === $token
            ? $this->staged->path(ImportAppRoute::STAGE, $token)
            : null;
        $name = is_array($import) && is_string($import['name'] ?? null) ? $import['name'] : '';
        $export = $path === null ? null : self::read($name, (string) file_get_contents($path));
        if ($export === null) {
            $session->flash('warning', 'import.expired');

            return $this->redirect->toRoute('import_app.upload');
        }

        $manageable = array_values(array_filter(
            $this->vehicles->listWith($user, VehicleAbility::Manage),
            static fn (Vehicle $v): bool => !$v->isArchived(),
        ));
        $guess = $this->importer->guess($user, $export, $manageable);
        $posted = $request->getMethod() === 'POST';
        $input = $posted ? RequestContext::form($request) : $request->getQueryParams();
        $options = AppImportOptions::fromQuery($input, $guess);

        $target = null;
        if ($options->vehicleId() !== null) {
            foreach ($manageable as $vehicle) {
                if ($vehicle->id === $options->vehicleId()) {
                    $target = $vehicle;
                }
            }
            if ($target === null) {
                throw new HttpNotFoundException($request);
            }
        }

        $previewing = $posted || array_key_exists('vehicle', $input);
        $preview = $previewing && !$options->skipsVehicle()
            ? $this->importer->analyse($user, $export, $options, $target)
            : null;
        $data = [
            'filename' => $name,
            'token' => $token,
            'export' => $export,
            'options' => $options,
            'manageable' => $manageable,
            'units_from_file' => $this->importer->unitsFromFile($user, $export),
            'preview' => $preview,
        ];
        if (!$posted || $preview === null) {
            return $this->render($request, $response, $data);
        }

        $importable = $preview->total(AppRowStatus::Import);
        $invalid = $preview->total(AppRowStatus::Invalid);
        $error = match (true) {
            $importable === 0 => ['key' => 'import_app.error.nothing', 'params' => []],
            $invalid > 0 && ($input['skip_invalid'] ?? '') !== '1'
                => ['key' => 'import.error.skip_required', 'params' => ['count' => $invalid]],
            default => null,
        };
        if ($error !== null) {
            return $this->render($request, $response, $data + ['error' => $error], 422);
        }

        [$done] = $this->importer->import($user, [$preview]);
        $this->staged->discard(ImportAppRoute::STAGE, $token);
        $session->remove(ImportAppRoute::SESSION);

        return $this->view->render($request, $response, 'import_app/result.twig', [
            'filename' => $name,
            'result' => $done,
            'sections' => AppVehiclePreview::SECTIONS,
            'statuses' => AppRowStatus::cases(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $data,
        int $status = 200,
    ): ResponseInterface {
        $preview = $data['preview'];
        $listed = [];
        $unlisted = [];
        if ($preview instanceof AppVehiclePreview) {
            foreach (AppVehiclePreview::SECTIONS as $section) {
                $importable = 0;
                $listed[$section] = array_values(array_filter(
                    $preview->rows($section),
                    static function (AppRow $row) use (&$importable): bool {
                        return $row->status !== AppRowStatus::Import || ++$importable <= self::PREVIEW_ROWS;
                    },
                ));
                $unlisted[$section] = max(0, $importable - self::PREVIEW_ROWS);
            }
        }
        $export = $data['export'];
        assert($export instanceof FuelioExport);

        return $this->view->render($request, $response, 'import_app/map.twig', $data + [
            'error' => null,
            'listed' => $listed,
            'unlisted' => $unlisted,
            'sections' => AppVehiclePreview::SECTIONS,
            'statuses' => AppRowStatus::cases(),
            'date_orders' => DateOrder::cases(),
            'distance_units' => DistanceUnit::cases(),
            'volume_units' => VolumeUnit::cases(),
            'maintenance_categories' => MaintenanceCategory::cases(),
            'expense_categories' => ExpenseCategory::cases(),
            'fuels' => Fuel::cases(),
            'grades' => array_map(static fn (Fuel $f): array => FuelGrade::forFamily($f), array_combine(
                array_map(static fn (Fuel $f): string => $f->value, Fuel::cases()),
                Fuel::cases(),
            )),
            'known_categories' => array_combine(
                $export->categoryIds(),
                array_map(FuelioImporter::knownCategory(...), $export->categoryIds()),
            ),
            'known_fuels' => array_combine(
                $export->fuelCodes(),
                array_map(FuelioImporter::knownFuelCode(...), $export->fuelCodes()),
            ),
            'sanity' => $preview instanceof AppVehiclePreview ? $this->sanity($preview) : null,
            'importable' => $preview instanceof AppVehiclePreview ? $preview->total(AppRowStatus::Import) : 0,
            'invalid' => $preview instanceof AppVehiclePreview ? $preview->total(AppRowStatus::Invalid) : 0,
            'query' => self::flatten($data['options'] instanceof AppImportOptions ? $data['options']->toQuery() : []),
        ], $status);
    }

    /**
     * The units' sanity line, formatted (spec.md §7.13 *Map*): Logbook's
     * average in the owner's unit and per 100 km, and the app's own.
     *
     * @return array{params: array<string, string>, app: string|null, matches: bool|null, unusual: bool}|null
     */
    private function sanity(AppVehiclePreview $preview): ?array
    {
        $sanity = $preview->sanity;
        if ($sanity === null) {
            return null;
        }
        [$km, $volume] = [$sanity->distanceKm, $sanity->volume];
        $canonical = match ($sanity->kind) {
            EnergyKind::Liquid => $this->format->consumption($km, $volume, 1, ConsumptionUnit::LitresPer100Km),
            EnergyKind::Electric => $this->format->efficiency($km, $volume, 1, ElectricEfficiencyUnit::KwhPer100Km),
            EnergyKind::Gas => $this->format->gasEconomy($km, $volume, 1, GasEfficiencyUnit::KgPer100Km),
        };
        $options = $preview->options;

        return [
            'params' => [
                'distance' => mb_strtolower($this->translator->trans('units.name.' . $options->distanceUnit->value)),
                'volume' => mb_strtolower($this->translator->trans('units.name.' . $options->volumeUnit->value)),
                'economy' => $this->format->economy($sanity->distanceKm, $sanity->volume, $sanity->kind),
                'canonical' => $canonical,
            ],
            'app' => $sanity->appPer100Km === null ? null : $this->format->consumption('100', (string) $sanity->appPer100Km),
            'matches' => $sanity->matchesApp(),
            'unusual' => $sanity->isUnusual(),
        ];
    }

    private static function read(string $name, string $contents): ?FuelioExport
    {
        try {
            $file = SectionSplitter::split($name, $contents);
        } catch (CsvTooLong) {
            return null;
        }

        return $file !== null && FuelioReader::detect($file) ? FuelioReader::read($file) : null;
    }

    /**
     * The options as flat form fields ("cat[5]" → "expense:parking"), for the
     * import form's hidden inputs.
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private static function flatten(array $query): array
    {
        $out = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $inner => $text) {
                    $out[sprintf('%s[%s]', $key, $inner)] = is_scalar($text) ? (string) $text : '';
                }
            } else {
                $out[$key] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $out;
    }
}
