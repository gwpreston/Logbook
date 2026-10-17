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
        if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url))) {
            throw new InvalidArgumentException(sprintf('OCR_URL "%s" must be an http:// or https:// address.', $url));
        }

        return new self(
            engine: OcrEngineChoice::fromEnv($env->string('OCR_ENGINE', 'auto')),
            tesseractBinary: $env->binary('TESSERACT_BINARY', 'tesseract'),
            paddleOcrUrl: $url,
            timeout: max(1, min(300, $env->int('OCR_TIMEOUT', 30))),
        );
    }
}
