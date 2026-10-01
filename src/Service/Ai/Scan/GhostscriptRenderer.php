<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use FilesystemIterator;
use Logbook\Support\Config\AppSettings;

/**
 * Renders PDF pages with Ghostscript (`GHOSTSCRIPT_BINARY`, default `gs` on
 * PATH). Run without a shell, from an argument list, with -dSAFER (no file
 * access beyond its input and output), a page limit and a timeout.
 */
final class GhostscriptRenderer implements PdfRenderer
{
    private const int TIMEOUT_SECONDS = 60;

    private ?string $resolved = null;
    private bool $looked = false;

    public function __construct(private readonly AppSettings $settings)
    {
    }

    public function isAvailable(): bool
    {
        return $this->binary() !== null && function_exists('proc_open');
    }

    public function render(string $pdfPath, int $pages, int $dpi): array
    {
        $binary = $this->binary();
        if ($binary === null || !is_file($pdfPath)) {
            return [];
        }
        $dir = sys_get_temp_dir() . '/logbook-gs-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) {
            return [];
        }

        try {
            $process = proc_open([
                $binary,
                '-q',
                '-dSAFER',
                '-dBATCH',
                '-dNOPAUSE',
                '-dNOPROMPT',
                '-sDEVICE=jpeg',
                '-dJPEGQ=85',
                '-r' . $dpi,
                '-dFirstPage=1',
                '-dLastPage=' . $pages,
                '-sOutputFile=' . $dir . '/page-%03d.jpg',
                $pdfPath,
            ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($process)) {
                return [];
            }
            $started = microtime(true);
            while (proc_get_status($process)['running']) {
                if (microtime(true) - $started > self::TIMEOUT_SECONDS) {
                    proc_terminate($process, 9);
                    proc_close($process);

                    return [];
                }
                usleep(50_000);
            }
            proc_close($process);

            $files = glob($dir . '/page-*.jpg') ?: [];
            sort($files);
            $images = [];
            foreach (array_slice($files, 0, $pages) as $file) {
                $bytes = file_get_contents($file);
                if (is_string($bytes) && $bytes !== '') {
                    $images[] = $bytes;
                }
            }

            return $images;
        } finally {
            foreach (new FilesystemIterator($dir) as $file) {
                if ($file instanceof \SplFileInfo) {
                    @unlink($file->getPathname());
                }
            }
            @rmdir($dir);
        }
    }

    private function binary(): ?string
    {
        if ($this->looked) {
            return $this->resolved;
        }
        $this->looked = true;
        $name = $this->settings->ai->ghostscriptBinary;
        if ($name === '') {
            return null;
        }
        if (str_contains($name, '/')) {
            return $this->resolved = is_file($name) && is_executable($name) ? $name : null;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $name;
            if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                return $this->resolved = $candidate;
            }
        }

        return null;
    }
}
