<?php

declare(strict_types=1);

namespace Logbook\Support\Storage;

/**
 * What an upload field accepts: vehicle photos take images only; receipts
 * and certificates (attachments) also take PDF.
 */
enum UploadKind
{
    case Image;
    case Document;
    /** An incident's photos and files: kept as uploaded (spec.md §7.12, Phase 27.1). */
    case Evidence;

    private const array IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Accepted MIME type (detected from the content) → stored file extension.
     *
     * @return array<string, string>
     */
    public function types(): array
    {
        return match ($this) {
            self::Image => self::IMAGE_TYPES,
            self::Document, self::Evidence => self::IMAGE_TYPES + ['application/pdf' => 'pdf'],
        };
    }

    /**
     * Translation key for a file of the wrong type.
     */
    public function typeError(): string
    {
        return match ($this) {
            self::Image => 'upload.not_an_image',
            self::Document, self::Evidence => 'upload.not_a_document',
        };
    }

    /**
     * Whether a photo is stored exactly as uploaded, EXIF and all, rather
     * than turned upright and stripped (decided 2026-10-01, #96).
     */
    public function keepsMetadata(): bool
    {
        return $this === self::Evidence;
    }
}
