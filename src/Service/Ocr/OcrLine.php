<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

/**
 * One line of words as the engine grouped them, inside a block.
 */
final readonly class OcrLine
{
    public string $text;
    public float $confidence;
    public OcrBox $box;

    /**
     * @param non-empty-list<OcrWord> $words
     */
    public function __construct(
        public array $words,
        /** The engine's block (paragraph or text region) this line is in, per page. */
        public int $block = 0,
        public int $page = 1,
    ) {
        $this->text = implode(' ', array_map(static fn (OcrWord $w): string => $w->text, $words));
        $this->confidence = array_sum(array_map(static fn (OcrWord $w): float => $w->confidence, $words)) / count($words);
        $this->box = OcrBox::around(array_map(static fn (OcrWord $w): OcrBox => $w->box, $words));
    }
}
