<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use SensitiveParameter;

/**
 * A provider's credentials, opened for one run and never stored or logged.
 */
final readonly class ProviderCredentials
{
    /**
     * @param array<string, string> $values by slot
     */
    public function __construct(#[SensitiveParameter] private array $values = [])
    {
    }

    public function get(string $slot): string
    {
        return $this->values[$slot] ?? '';
    }

    /**
     * @return list<string> the values, for the job's redaction
     */
    public function values(): array
    {
        return array_values(array_filter($this->values, static fn (string $value): bool => $value !== ''));
    }
}
