<?php

declare(strict_types=1);

namespace Logbook\Action\Import;

use Logbook\Service\Export\ExportModule;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Import\CsvImporter;
use Logbook\Service\Import\ImportField;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Csv\CsvReader;
use Logbook\Support\Csv\CsvTooLong;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\StagedFiles;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * GET|POST /vehicles/{id}/import/{module} — CSV import, step 1: choose the
 * file (spec.md §7.13). A readable file is kept for the next step (column
 * mapping and preview); nothing is imported yet.
 */
final readonly class ImportUploadAction
{
    public function __construct(
        private FeatureToggles $features,
        private StagedFiles $staged,
        private AppSettings $settings,
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

        if ($request->getMethod() !== 'POST') {
            return $this->render($request, $response, $vehicle, $module);
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || !FileUpload::wasProvided($file)) {
            return $this->render($request, $response, $vehicle, $module, ['key' => 'import.error.no_file', 'params' => []]);
        }
        $maxBytes = $this->settings->maxUploadMb * 1024 * 1024;
        if (
            in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || ($file->getSize() ?? 0) > $maxBytes
        ) {
            return $this->render($request, $response, $vehicle, $module, [
                'key' => 'upload.too_large',
                'params' => [],
            ]);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->render($request, $response, $vehicle, $module, ['key' => 'upload.failed', 'params' => []]);
        }

        try {
            $csv = CsvReader::read((string) $file->getStream(), CsvImporter::MAX_ROWS);
        } catch (CsvTooLong $e) {
            return $this->render($request, $response, $vehicle, $module, [
                'key' => 'import.error.too_many_rows',
                'params' => ['max' => $e->maxRows],
            ]);
        }
        if ($csv === null || $csv->rows === []) {
            return $this->render($request, $response, $vehicle, $module, ['key' => 'import.error.empty', 'params' => []]);
        }

        $session = RequestContext::session($request);
        $previous = $session->get(ImportRoute::SESSION);
        if (is_array($previous) && is_string($previous['token'] ?? null)) {
            $this->staged->discard('import', $previous['token']);
        }
        $token = $this->staged->stage($file, 'import');
        $session->set(ImportRoute::SESSION, [
            'token' => $token,
            'vehicle' => $vehicle->id,
            'module' => $module->value,
            'name' => mb_substr(basename((string) $file->getClientFilename()), 0, 120),
        ]);

        return $this->redirect->toRoute('import.map', [
            'id' => (string) $vehicle->id,
            'module' => $module->value,
            'token' => $token,
        ]);
    }

    /**
     * @param array{key: string, params: array<string, int|string>}|null $error
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        ExportModule $module,
        ?array $error = null,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'import/upload.twig', [
            'vehicle' => $vehicle,
            'module' => $module,
            'fields' => ImportField::forModule($module),
            'error' => $error,
            'max_upload_mb' => $this->settings->maxUploadMb,
            'max_rows' => CsvImporter::MAX_ROWS,
        ], $error === null ? 200 : 422);
    }
}
