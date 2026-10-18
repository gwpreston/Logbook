<?php

declare(strict_types=1);

namespace Logbook\Support\Config;

use InvalidArgumentException;

/**
 * Reading text from images (spec.md §9, §7.27, Phase 53.1). OCR isn't an AI
 * feature: it works with `AI_ENABLED=false` and with no model set up.
 */
final readonly class OcrConfig
{
    public function __construct(
        /** `OCR_ENGINE`: auto | tesseract | paddleocr | off. */
        public OcrEngineChoice $engine = OcrEngineChoice::Auto,
        /** `TESSERACT_BINARY`: a name on PATH or a full path; '' (from `off`) turns it off. */
        public string $tesseractBinary = 'tesseract',
        /** `OCR_URL`: a PaddleOCR (PaddleX serving) address, or '' for none. */
        public string $paddleOcrUrl = '',
        /** `OCR_TIMEOUT`: seconds one page may take. */
        public int $timeout = 30,
    ) {
    }

    public static function fromEnv(Env $env): self
    {
        $url = rtrim($env->string('OCR_URL'), '/');
        if ($url !== '' && !self::isHttpAddress($url)) {
            // The value isn't repeated: it may carry a password.
            throw new InvalidArgumentException(
                'OCR_URL must be an http:// or https:// address with a host name, and no query string or fragment.',
            );
        }

        return new self(
            engine: OcrEngineChoice::fromEnv($env->string('OCR_ENGINE', 'auto')),
            tesseractBinary: $env->binary('TESSERACT_BINARY', 'tesseract'),
            paddleOcrUrl: $url,
            timeout: max(1, min(300, $env->int('OCR_TIMEOUT', 30))),
        );
    }

    /**
     * http(s) with a host, and no query or fragment: requests go to
     * `{OCR_URL}/ocr`, which a `?` or `#` would swallow (K77). Checked with parse_url rather than
     * FILTER_VALIDATE_URL, which refuses an underscore in a host name, as
     * Docker service and container names often have (`paddle_ocr`).
     */
    private static function isHttpAddress(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !str_contains($url, '?')
            && !str_contains($url, '#')
            && preg_match('/\s/', $url) !== 1;
    }

    /**
     * The address for showing: scheme, host, port and path only, so no user
     * name, password (even one with a raw `@`), query or fragment.
     */
    public function paddleOcrAddress(): string
    {
        $parts = parse_url($this->paddleOcrUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '');
    }
}
