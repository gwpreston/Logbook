<?php

declare(strict_types=1);

namespace Logbook\Support\Storage;

use Logbook\Support\Config\AppSettings;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Short-lived uploads waiting for the next step of a multi-step form (a CSV
 * import's column mapping, a restore's confirmation): kept under
 * var/cache/staged with a random token as the name, and removed once used
 * or after a day. Callers bind the token to the session, so a token alone
 * never reaches a file.
 */
final readonly class StagedFiles
{
    private const int MAX_AGE_SECONDS = 24 * 3600;

    private string $directory;

    public function __construct(AppSettings $settings)
    {
        $this->directory = $settings->cacheDir . '/staged';
    }

    /**
     * Keep an upload; returns its token (32 hex characters).
     *
     * @param string $kind e.g. "import" or "restore" (letters only)
     */
    public function stage(UploadedFileInterface $file, string $kind): string
    {
        $this->prepare();
        $token = bin2hex(random_bytes(16));
        $file->moveTo($this->file($kind, $token));

        return $token;
    }

    /**
     * The staged file's path, or null when there is none (used, expired, or
     * a token that was never issued).
     */
    public function path(string $kind, string $token): ?string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }
        $path = $this->file($kind, $token);

        return is_file($path) && filemtime($path) >= time() - self::MAX_AGE_SECONDS ? $path : null;
    }

    public function discard(string $kind, string $token): void
    {
        $path = $this->path($kind, $token);
        if ($path !== null) {
            unlink($path);
        }
    }

    private function file(string $kind, string $token): string
    {
        if (preg_match('/^[a-z]+$/', $kind) !== 1) {
            throw new RuntimeException(sprintf('Invalid staged file kind "%s".', $kind));
        }

        return $this->directory . '/' . $kind . '-' . $token;
    }

    /**
     * Create the directory, and sweep anything left over from abandoned forms.
     */
    private function prepare(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Cannot create "%s".', $this->directory));
        }
        foreach (glob($this->directory . '/*') ?: [] as $old) {
            if (is_file($old) && filemtime($old) < time() - self::MAX_AGE_SECONDS) {
                @unlink($old);
            }
        }
    }
}
