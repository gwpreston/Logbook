<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Service\Ai\Provider\ImageInput;

/**
 * A scanned file ready for a model: its text (a text PDF) or its images (a
 * photo, or a scanned PDF's rendered pages). Neither when it cannot be
 * read; then $problem says why.
 */
final readonly class PreparedFile
{
    /**
     * @param list<ImageInput> $images
     */
    private function __construct(
        public ?string $text,
        public array $images,
        public ?int $pageCount,
        /** Why it cannot be sent (a ScanProblem value), or null. */
        public ?ScanProblem $problem = null,
    ) {
    }

    public static function text(string $text, int $pageCount): self
    {
        return new self($text, [], $pageCount);
    }

    /**
     * @param list<ImageInput> $images
     */
    public static function images(array $images, ?int $pageCount = null): self
    {
        return new self(null, $images, $pageCount);
    }

    public static function unreadable(ScanProblem $problem, ?int $pageCount = null): self
    {
        return new self(null, [], $pageCount, $problem);
    }

    public function isText(): bool
    {
        return $this->text !== null;
    }
}
