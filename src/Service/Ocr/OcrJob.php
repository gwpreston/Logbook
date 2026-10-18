<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

use Closure;

/**
 * A file's OCR in progress (OcrReader::begin()): the first page started,
 * the others read when the result is collected. A later page that fails
 * leaves the pages already read.
 */
final class OcrJob
{
    private ?OcrResult $result = null;

    /**
     * @param Closure(int): OcrResult $page reads page $i (0-based) now
     */
    public function __construct(
        private readonly OcrRun $first,
        private readonly int $pages,
        private readonly Closure $page,
    ) {
    }

    public static function failed(OcrResult $result): self
    {
        return new self(OcrRun::done($result), 1, static fn (): OcrResult => $result);
    }

    public function result(): OcrResult
    {
        if ($this->result !== null) {
            return $this->result;
        }
        $read = $this->first->result();
        if (!$read->ok()) {
            return $this->result = $read;
        }
        $lines = $read->lines;
        for ($i = 1; $i < $this->pages; $i++) {
            $next = ($this->page)($i);
            if (!$next->ok()) {
                break;
            }
            array_push($lines, ...$next->lines);
        }

        return $this->result = OcrResult::read($lines, $read->language, $read->engineVersion);
    }
}
