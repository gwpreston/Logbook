<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

use Closure;

/**
 * One page's read, started by OcrEngine::start() and collected by
 * result(), so a model's request can run in between (spec.md §7.27 *The
 * cross-check*, Phase 53.3). An engine that can't run in the background
 * reads when result() is called.
 */
final class OcrRun
{
    private ?OcrResult $result = null;

    /**
     * @param Closure(): OcrResult $collect
     * @param (Closure(): void)|null $abandon tidies up a run nobody collected
     */
    public function __construct(
        private readonly Closure $collect,
        private readonly ?Closure $abandon = null,
    ) {
    }

    public function __destruct()
    {
        if ($this->result === null && $this->abandon !== null) {
            ($this->abandon)();
        }
    }

    public static function done(OcrResult $result): self
    {
        $run = new self(static fn (): OcrResult => $result);
        $run->result = $result;

        return $run;
    }

    /**
     * Whether the read is over already (a failure before it could start),
     * not still running.
     */
    public function settled(): bool
    {
        return $this->result !== null;
    }

    public function result(): OcrResult
    {
        return $this->result ??= ($this->collect)();
    }
}
