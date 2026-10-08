<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

/**
 * The MOT history providers this install knows (spec.md §7.38 *Provider*).
 */
final readonly class MotHistoryRegistry
{
    /** @var array<string, MotHistoryProvider> */
    private array $providers;

    /**
     * @param list<MotHistoryProvider> $providers
     */
    public function __construct(array $providers)
    {
        $byCode = [];
        foreach ($providers as $provider) {
            $byCode[$provider->code()] = $provider;
        }
        $this->providers = $byCode;
    }

    public function get(?string $code): ?MotHistoryProvider
    {
        return $code === null ? null : ($this->providers[$code] ?? null);
    }

    /**
     * @return list<MotHistoryProvider>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }
}
