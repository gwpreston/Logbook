<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Service\Ai\Provider\ProviderAdapter;

/**
 * A connection's adapter, ready to call, with the plain secret values to
 * redact from anything it reports.
 */
final readonly class OpenedConnection
{
    public function __construct(
        public ProviderAdapter $adapter,
        /** @var list<string> */
        public array $secrets,
    ) {
    }

    public function redact(string $text): string
    {
        return Redactor::redact($text, $this->secrets);
    }
}
