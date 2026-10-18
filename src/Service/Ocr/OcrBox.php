<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

/**
 * A rectangle on the page OCR read, in pixels of the prepared image (spec.md
 * §7.27 *Reading text from images*).
 */
final readonly class OcrBox
{
    public function __construct(
        public int $left,
        public int $top,
        public int $width,
        public int $height,
    ) {
    }

    public function right(): int
    {
        return $this->left + $this->width;
    }

    public function bottom(): int
    {
        return $this->top + $this->height;
    }

    /**
     * The smallest box holding all of $boxes.
     *
     * @param non-empty-list<self> $boxes
     */
    public static function around(array $boxes): self
    {
        $left = min(array_map(static fn (self $b): int => $b->left, $boxes));
        $top = min(array_map(static fn (self $b): int => $b->top, $boxes));
        $right = max(array_map(static fn (self $b): int => $b->right(), $boxes));
        $bottom = max(array_map(static fn (self $b): int => $b->bottom(), $boxes));

        return new self($left, $top, $right - $left, $bottom - $top);
    }
}
