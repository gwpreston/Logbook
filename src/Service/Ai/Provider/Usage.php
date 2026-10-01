<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

/**
 * Tokens in and out, when the provider reports them.
 */
final readonly class Usage
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {
    }
}
