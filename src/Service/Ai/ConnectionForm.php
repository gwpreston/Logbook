<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Domain\Ai\ConnectionSettings;

/**
 * A validated connection form: the settings, and what to do with each
 * secret (spec.md §7.25 *Secrets*). Secret values are never put back into
 * a form.
 */
final readonly class ConnectionForm
{
    public function __construct(
        public ConnectionSettings $settings,
        /** A new API key or `env:` reference; null keeps the saved one. */
        public ?string $apiKey,
        public bool $removeApiKey,
        /** @var array<string, string> header name => new value or reference */
        public array $headers,
        /** @var list<string> saved header names to remove */
        public array $removeHeaders,
        public bool $acknowledge,
    ) {
    }
}
