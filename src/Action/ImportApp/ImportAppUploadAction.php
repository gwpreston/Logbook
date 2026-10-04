<?php

declare(strict_types=1);

namespace Logbook\Action\ImportApp;

use Logbook\Service\Import\App\Fuelio\FuelioReader;
use Logbook\Service\Import\App\SectionSplitter;
use Logbook\Support\Config\AppSettings;
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
 * GET|POST /settings/import-app — importing from another app, step 1:
 * upload a Fuelio CSV (spec.md §7.13 *Importing from another app*). A
 * Fuelio export is kept for the mapping step; anything else, or a backup
 * ZIP (the command line's), gets a clear message.
 */
final readonly class ImportAppUploadAction
{
    public function __construct(
        private StagedFiles $staged,
        private AppSettings $settings,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->render($request, $response);
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || !FileUpload::wasProvided($file)) {
            return $this->render($request, $response, ['key' => 'import.error.no_file', 'params' => []]);
        }
        $name = mb_substr(basename(str_replace('\\', '/', (string) $file->getClientFilename())), 0, 120);
        $maxBytes = $this->settings->maxUploadMb * 1024 * 1024;
        // A backup ZIP is the command line's (spec.md §7.13), by its name or its bytes.
        $onCli = ['key' => 'import_app.error.zip_on_cli', 'params' => ['command' => ImportAppRoute::COMMAND]];
        if (str_ends_with(strtolower($name), '.zip')) {
            return $this->render($request, $response, $onCli);
        }
        $tooLarge = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || ($file->getSize() ?? 0) > $maxBytes;
        if ($tooLarge) {
            return $this->render($request, $response, ['key' => 'upload.too_large', 'params' => []]);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->render($request, $response, ['key' => 'upload.failed', 'params' => []]);
        }

        $contents = (string) $file->getStream();
        if (str_starts_with($contents, "PK\x03\x04")) {
            return $this->render($request, $response, $onCli);
        }
        try {
            $split = SectionSplitter::split($name, $contents);
        } catch (CsvTooLong $e) {
            return $this->render($request, $response, [
                'key' => 'import.error.too_many_rows',
                'params' => ['max' => $e->maxRows],
            ]);
        }
        if ($split === null || !FuelioReader::detect($split)) {
            return $this->render($request, $response, ['key' => 'import_app.error.not_fuelio', 'params' => []]);
        }

        $session = RequestContext::session($request);
        $previous = $session->get(ImportAppRoute::SESSION);
        if (is_array($previous) && is_string($previous['token'] ?? null)) {
            $this->staged->discard(ImportAppRoute::STAGE, $previous['token']);
        }
        $token = $this->staged->stage($file, ImportAppRoute::STAGE);
        $session->set(ImportAppRoute::SESSION, ['token' => $token, 'name' => $name]);

        return $this->redirect->toRoute('import_app.map', ['token' => $token]);
    }

    /**
     * @param array{key: string, params: array<string, int|string>}|null $error
     */
    private function render(ServerRequestInterface $request, ResponseInterface $response, ?array $error = null): ResponseInterface
    {
        return $this->view->render($request, $response, 'import_app/upload.twig', [
            'error' => $error,
            'max_upload_mb' => $this->settings->maxUploadMb,
            'max_rows' => SectionSplitter::MAX_ROWS,
            'command' => ImportAppRoute::COMMAND,
        ], $error === null ? 200 : 422);
    }
}
