<?php

declare(strict_types=1);

namespace Logbook\Action\ImportApp;

use Logbook\Service\Import\App\ArchiveReader;
use Logbook\Service\Import\App\ArchiveRefused;
use Logbook\Service\Import\App\Fuelio\FuelioReader;
use Logbook\Service\Import\App\SectionSplitter;
use Logbook\Service\Import\App\Tracktor\TracktorReader;
use Logbook\Service\Import\App\Tracktor\TracktorRefused;
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
 * upload a Fuelio CSV or a Tracktor JSON export, with Tracktor's uploads
 * folder as an optional ZIP (spec.md §7.13 *Importing from another app*,
 * *The Tracktor reader*). The export (and the ZIP, once checked) is kept
 * for the mapping step; anything else, or a Fuelio backup ZIP (the command
 * line's), gets a clear message.
 */
final readonly class ImportAppUploadAction
{
    public function __construct(
        private StagedFiles $staged,
        private ArchiveReader $archives,
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

        $uploads = $request->getUploadedFiles();
        $file = $uploads['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || !FileUpload::wasProvided($file)) {
            return $this->render($request, $response, ['key' => 'import.error.no_file', 'params' => []]);
        }
        $name = self::name($file);
        // A backup ZIP is the command line's (spec.md §7.13), by its name or its bytes.
        $onCli = ['key' => 'import_app.error.zip_on_cli', 'params' => ['command' => ImportAppRoute::COMMAND]];
        if (str_ends_with(strtolower($name), '.zip')) {
            return $this->render($request, $response, $onCli);
        }
        $problem = $this->sizeProblem($file);
        if ($problem !== null) {
            return $this->render($request, $response, $problem);
        }

        $contents = (string) $file->getStream();
        if (str_starts_with($contents, "PK\x03\x04")) {
            return $this->render($request, $response, $onCli);
        }
        $files = $uploads['files'] ?? null;
        $files = $files instanceof UploadedFileInterface && FileUpload::wasProvided($files) ? $files : null;

        if (TracktorReader::detect($contents)) {
            try {
                TracktorReader::read($name, $contents);
            } catch (TracktorRefused $e) {
                return $this->render($request, $response, ['key' => $e->key, 'params' => $e->params]);
            }

            return $this->stageTracktor($request, $response, $file, $name, $files);
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
        if ($files !== null) {
            return $this->render($request, $response, ['key' => 'import_app.error.files_for_tracktor', 'params' => []], 'files');
        }

        $token = $this->restage($request, $file);
        RequestContext::session($request)->set(ImportAppRoute::SESSION, ['token' => $token, 'name' => $name]);

        return $this->redirect->toRoute('import_app.map', ['token' => $token]);
    }

    /**
     * Keep a Tracktor export, and its uploads ZIP once it has passed the
     * ZIP rules.
     */
    private function stageTracktor(
        ServerRequestInterface $request,
        ResponseInterface $response,
        UploadedFileInterface $file,
        string $name,
        ?UploadedFileInterface $files,
    ): ResponseInterface {
        $filesToken = null;
        $filesName = null;
        if ($files !== null) {
            $filesName = self::name($files);
            $problem = $this->sizeProblem($files);
            if ($problem !== null) {
                return $this->render($request, $response, $problem, 'files');
            }
            if (!str_starts_with((string) $files->getStream(), "PK\x03\x04")) {
                return $this->render($request, $response, ['key' => 'import_app.error.files_not_zip', 'params' => []], 'files');
            }
            $filesToken = $this->staged->stage($files, ImportAppRoute::FILES_STAGE);
            $path = $this->staged->path(ImportAppRoute::FILES_STAGE, $filesToken) ?? '';
            try {
                $this->archives->openUploads($path)->close();
            } catch (ArchiveRefused $e) {
                $this->staged->discard(ImportAppRoute::FILES_STAGE, $filesToken);

                return $this->render($request, $response, ['key' => $e->key, 'params' => $e->params], 'files');
            }
        }

        $token = $this->restage($request, $file);
        RequestContext::session($request)->set(ImportAppRoute::SESSION, [
            'token' => $token,
            'name' => $name,
            'app' => TracktorReader::APP,
            'files' => $filesToken,
            'files_name' => $filesName,
        ]);

        return $this->redirect->toRoute('import_app.map', ['token' => $token]);
    }

    /**
     * Discard the import in progress, if any, and stage this file.
     */
    private function restage(ServerRequestInterface $request, UploadedFileInterface $file): string
    {
        $previous = RequestContext::session($request)->get(ImportAppRoute::SESSION);
        if (is_array($previous)) {
            if (is_string($previous['token'] ?? null)) {
                $this->staged->discard(ImportAppRoute::STAGE, $previous['token']);
            }
            if (is_string($previous['files'] ?? null)) {
                $this->staged->discard(ImportAppRoute::FILES_STAGE, $previous['files']);
            }
        }

        return $this->staged->stage($file, ImportAppRoute::STAGE);
    }

    /**
     * @return array{key: string, params: array<string, int|string>}|null
     */
    private function sizeProblem(UploadedFileInterface $file): ?array
    {
        $tooLarge = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || ($file->getSize() ?? 0) > $this->settings->maxUploadMb * 1024 * 1024;

        return match (true) {
            $tooLarge => ['key' => 'upload.too_large', 'params' => []],
            $file->getError() !== UPLOAD_ERR_OK => ['key' => 'upload.failed', 'params' => []],
            default => null,
        };
    }

    private static function name(UploadedFileInterface $file): string
    {
        return mb_substr(basename(str_replace('\\', '/', (string) $file->getClientFilename())), 0, 120);
    }

    /**
     * @param array{key: string, params: array<string, int|string>}|null $error
     * @param 'file'|'files' $field the field the error belongs to
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?array $error = null,
        string $field = 'file',
    ): ResponseInterface {
        return $this->view->render($request, $response, 'import_app/upload.twig', [
            'error' => $error,
            'error_field' => $field,
            'max_upload_mb' => $this->settings->maxUploadMb,
            'max_rows' => SectionSplitter::MAX_ROWS,
            'command' => ImportAppRoute::COMMAND,
            'tracktor_command' => ImportAppRoute::TRACKTOR_COMMAND,
        ], $error === null ? 200 : 422);
    }
}
