<?php

declare(strict_types=1);

namespace Logbook\Action\ImportApp;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Import\App\AppFiles;
use Logbook\Service\Import\App\AppRow;
use Logbook\Service\Import\App\AppRowStatus;
use Logbook\Service\Import\App\ArchiveReader;
use Logbook\Service\Import\App\ArchiveRefused;
use Logbook\Service\Import\App\Tracktor\TracktorExport;
use Logbook\Service\Import\App\Tracktor\TracktorImporter;
use Logbook\Service\Import\App\Tracktor\TracktorImportOptions;
use Logbook\Service\Import\App\Tracktor\TracktorPreview;
use Logbook\Service\Import\App\Tracktor\TracktorReader;
use Logbook\Service\Import\App\Tracktor\TracktorRefused;
use Logbook\Service\Import\App\Tracktor\TracktorVehiclePreview;
use Logbook\Service\Import\App\UnitSanity;
use Logbook\Service\Vehicle\VehicleService;
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
 * The mapping, preview and import steps for a Tracktor export (spec.md
 * §7.13 *The Tracktor reader*), on the same page as Fuelio's: a GET form,
 * bookmarkable and working without JS, a card per vehicle in the preview,
 * and every vehicle imported in one transaction (POST). Only active
 * vehicles the user can manage are offered; any other vehicle in the form
 * is a 404. ImportAppAction hands Tracktor imports to it.
 */
final readonly class ImportTracktorPage
{
    /** Importable rows listed per section (every other row is always listed). */
    private const int PREVIEW_ROWS = 50;

    public function __construct(
        private TracktorImporter $importer,
        private VehicleService $vehicles,
        private StagedFiles $staged,
        private ArchiveReader $archives,
        private DisplayFormatter $format,
        private TranslatorInterface $translator,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<array-key, mixed> $import the session's record of the import in progress
     */
    public function handle(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $token,
        array $import,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);
        $session = RequestContext::session($request);
        $name = is_string($import['name'] ?? null) ? $import['name'] : '';
        $path = $this->staged->path(ImportAppRoute::STAGE, $token);
        $export = $path === null ? null : $this->read($user, $name, (string) file_get_contents($path));
        $filesToken = is_string($import['files'] ?? null) ? $import['files'] : null;
        $filesPath = $filesToken === null ? null : $this->staged->path(ImportAppRoute::FILES_STAGE, $filesToken);
        if ($export === null || ($filesToken !== null && $filesPath === null)) {
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
        $options = TracktorImportOptions::fromQuery($input, $guess);
        $targets = [];
        foreach ($export->vehicles as $vehicle) {
            $id = $options->vehicleId($vehicle->id);
            if ($id === null) {
                continue;
            }
            $target = null;
            foreach ($manageable as $candidate) {
                if ($candidate->id === $id) {
                    $target = $candidate;
                }
            }
            if ($target === null) {
                throw new HttpNotFoundException($request);
            }
            $targets[$vehicle->id] = $target;
        }

        $files = $this->files($filesPath);
        try {
            $previewing = $posted || array_key_exists('vehicle', $input);
            $preview = $previewing ? $this->importer->analyse($user, $export, $options, $targets, $manageable, $files) : null;
            $data = [
                'filename' => $name,
                'files_name' => is_string($import['files_name'] ?? null) ? $import['files_name'] : null,
                'token' => $token,
                'export' => $export,
                'options' => $options,
                'manageable' => $manageable,
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

            $done = $this->importer->import($user, $preview, $files);
        } finally {
            $files?->close();
        }
        $this->staged->discard(ImportAppRoute::STAGE, $token);
        if ($filesToken !== null) {
            $this->staged->discard(ImportAppRoute::FILES_STAGE, $filesToken);
        }
        $session->remove(ImportAppRoute::SESSION);

        return $this->view->render($request, $response, 'import_app/tracktor_result.twig', [
            'filename' => $name,
            'result' => $done,
            'imported' => $done->total(AppRowStatus::Import),
            'sections' => TracktorVehiclePreview::SECTIONS,
            'statuses' => AppRowStatus::cases(),
        ]);
    }

    private function read(User $user, string $name, string $contents): ?TracktorExport
    {
        try {
            return TracktorReader::read($name, $contents, $user->preferences->timeZone());
        } catch (TracktorRefused) {
            return null;
        }
    }

    private function files(?string $path): ?AppFiles
    {
        if ($path === null) {
            return null;
        }
        try {
            return $this->archives->openUploads($path);
        } catch (ArchiveRefused) {
            return null;
        }
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
        $export = $data['export'];
        $options = $data['options'];
        assert($export instanceof TracktorExport && $options instanceof TracktorImportOptions);
        $cards = [];
        if ($preview instanceof TracktorPreview) {
            foreach ($preview->vehicles as $vehicle) {
                $listed = [];
                $unlisted = [];
                foreach (TracktorVehiclePreview::SECTIONS as $section) {
                    $importable = 0;
                    $listed[$section] = array_values(array_filter(
                        $vehicle->rows($section),
                        static function (AppRow $row) use (&$importable): bool {
                            return $row->status !== AppRowStatus::Import || ++$importable <= self::PREVIEW_ROWS;
                        },
                    ));
                    $unlisted[$section] = max(0, $importable - self::PREVIEW_ROWS);
                }
                $cards[] = [
                    'vehicle' => $vehicle,
                    'listed' => $listed,
                    'unlisted' => $unlisted,
                    'sanity' => $vehicle->sanity === null ? null : $this->sanity($vehicle->sanity, $options),
                ];
            }
        }

        return $this->view->render($request, $response, 'import_app/tracktor_map.twig', $data + [
            'error' => null,
            'cards' => $cards,
            'sections' => TracktorVehiclePreview::SECTIONS,
            'statuses' => AppRowStatus::cases(),
            'distance_units' => DistanceUnit::cases(),
            'volume_units' => VolumeUnit::cases(),
            'maintenance_categories' => MaintenanceCategory::cases(),
            'document_types' => ComplianceType::cases(),
            'units_from_file' => TracktorImporter::unitsFromFile($export),
            'importable' => $preview instanceof TracktorPreview ? $preview->total(AppRowStatus::Import) : 0,
            'invalid' => $preview instanceof TracktorPreview ? $preview->total(AppRowStatus::Invalid) : 0,
            'files_behind' => $preview instanceof TracktorPreview ? $preview->filesLeftBehind() : 0,
            'query' => ImportAppAction::flatten($options->toQuery()),
        ], $status);
    }

    /**
     * The units' sanity line, formatted (spec.md §7.13): Logbook's average
     * in the owner's unit and per 100 km. Tracktor's export has no economy
     * of its own to compare.
     *
     * @return array{params: array<string, string>, unusual: bool}
     */
    private function sanity(UnitSanity $sanity, TracktorImportOptions $options): array
    {
        [$km, $volume] = [$sanity->distanceKm, $sanity->volume];
        $canonical = match ($sanity->kind) {
            EnergyKind::Liquid => $this->format->consumption($km, $volume, 1, ConsumptionUnit::LitresPer100Km),
            EnergyKind::Electric => $this->format->efficiency($km, $volume, 1, ElectricEfficiencyUnit::KwhPer100Km),
            EnergyKind::Gas => $this->format->gasEconomy($km, $volume, 1, GasEfficiencyUnit::KgPer100Km),
        };

        return [
            'params' => [
                'distance' => ImportAppAction::inSentence($this->translator, 'units.name.' . $options->distanceUnit->value),
                'volume' => ImportAppAction::inSentence($this->translator, 'units.name.' . $options->volumeUnit->value),
                'economy' => $this->format->economy($km, $volume, $sanity->kind),
                'canonical' => $canonical,
            ],
            'unusual' => $sanity->isUnusual(),
        ];
    }
}
