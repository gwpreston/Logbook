<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

use FilesystemIterator;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Process\Executable;
use Logbook\Support\Process\ProcessRunner;
use Logbook\Support\Process\RunningProcess;

/**
 * Tesseract on this server (`TESSERACT_BINARY`, spec.md §9, §7.27). Run
 * without a shell from an argument list, one thread, with `OCR_TIMEOUT`;
 * its TSV is written to a temporary file and read back.
 */
final class TesseractEngine implements OcrEngine
{
    private ?string $resolved = null;
    private bool $looked = false;
    private ?OcrEngineStatus $status = null;

    public function __construct(
        private readonly AppSettings $settings,
        private readonly ProcessRunner $runner,
    ) {
    }

    public function name(): string
    {
        return 'tesseract';
    }

    public function isAvailable(): bool
    {
        return $this->binary() !== null && function_exists('proc_open');
    }

    public function status(): OcrEngineStatus
    {
        if ($this->status !== null) {
            return $this->status;
        }
        $binary = $this->binary();
        if ($binary === null || !function_exists('proc_open')) {
            return $this->status = new OcrEngineStatus($this->name(), false);
        }
        $version = $this->runner->run([$binary, '--version'], 10);
        if (!$version->ok()) {
            // K94: one that fails or hangs on its version isn't asked for its languages too.
            return $this->status = new OcrEngineStatus($this->name(), false, location: $binary);
        }
        $langs = $this->runner->run([$binary, '--list-langs'], 10);

        return $this->status = new OcrEngineStatus(
            $this->name(),
            true,
            self::version($version->stdout . "\n" . $version->stderr),
            self::languages($langs->stdout . "\n" . $langs->stderr),
            $binary,
        );
    }

    public function read(string $png, array $languages, PageLayout $layout, int $page = 1): OcrResult
    {
        return $this->start($png, $languages, $layout, $page)->result();
    }

    /**
     * Started in the background (Phase 53.3): the run's result waits for
     * it, with `OCR_TIMEOUT` counted from the start.
     */
    public function start(string $png, array $languages, PageLayout $layout, int $page = 1): OcrRun
    {
        $binary = $this->binary();
        if ($binary === null || !function_exists('proc_open')) {
            return OcrRun::done(OcrResult::failed(OcrProblem::Unavailable));
        }
        $status = $this->status();
        $language = $this->pick($languages, $status->languages);
        $dir = sys_get_temp_dir() . '/logbook-ocr-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700)) {
            return OcrRun::done(OcrResult::failed(OcrProblem::Failed, $language, $status->version));
        }
        if (file_put_contents($dir . '/page.png', $png) === false) {
            self::remove($dir);

            return OcrRun::done(OcrResult::failed(OcrProblem::Failed, $language, $status->version));
        }
        $running = $this->runner->start(
            [$binary, $dir . '/page.png', $dir . '/out', '-l', $language, '--psm', (string) $layout->tesseractMode(), 'tsv'],
            $this->settings->ocr->timeout,
            ['OMP_THREAD_LIMIT' => '1'],
        );

        return new OcrRun(static function () use ($running, $dir, $page, $language, $status): OcrResult {
            try {
                $run = $running instanceof RunningProcess ? $running->wait() : $running;
                if ($run->timedOut) {
                    return OcrResult::failed(OcrProblem::Timeout, $language, $status->version);
                }
                $tsv = is_file($dir . '/out.tsv') ? file_get_contents($dir . '/out.tsv') : false;
                if (!$run->ok() || !is_string($tsv)) {
                    return OcrResult::failed(OcrProblem::Failed, $language, $status->version);
                }

                return OcrResult::read(TesseractTsv::parse($tsv, $page), $language, $status->version);
            } finally {
                self::remove($dir);
            }
        }, static function () use ($running, $dir): void {
            // Stopped before its directory goes, so a late write can't leave the OCR text behind (K116).
            if ($running instanceof RunningProcess) {
                $running->stop();
            }
            self::remove($dir);
        });
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new FilesystemIterator($dir) as $file) {
            if ($file instanceof \SplFileInfo) {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    /**
     * The wanted languages it has, joined as Tesseract takes them; English
     * when it has none of them (or its list couldn't be read).
     *
     * @param list<string> $wanted
     * @param list<string> $installed
     */
    private function pick(array $wanted, array $installed): string
    {
        $have = $installed === [] ? $wanted : array_values(array_intersect($wanted, $installed));

        return $have === [] ? 'eng' : implode('+', array_unique($have));
    }

    /** `tesseract 5.3.0` from the first line of `--version` (stdout in 5.x, stderr in 4.x). */
    private static function version(string $output): string
    {
        return preg_match('/tesseract\s+v?(\d+(?:\.\d+)+\S*)/i', $output, $m) === 1 ? 'tesseract ' . $m[1] : 'tesseract';
    }

    /**
     * @return list<string>
     */
    private static function languages(string $output): array
    {
        $languages = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^[a-z]{3}(?:_[a-z]+)*$/i', $line) === 1 && $line !== 'osd') {
                $languages[] = $line;
            }
        }
        sort($languages);

        return array_values(array_unique($languages));
    }

    private function binary(): ?string
    {
        if (!$this->looked) {
            $this->looked = true;
            $this->resolved = Executable::find($this->settings->ocr->tesseractBinary);
        }

        return $this->resolved;
    }
}
